<?php

namespace Kanboard\Plugin\PropertiesFromDescription;

use Kanboard\Core\Plugin\Base;
use Kanboard\Core\Translator;

class Plugin extends Base
{
    public function initialize()
    {
        // modify task after saving
        $this->hook->on('model:task:creation:aftersave', function (int $task_id) {
            $this->parseDescription($task_id);
        });
    }

    private function parseDescription(int $task_id)
    {
        // query task from database
        $task = $this->taskFinderModel->getById($task_id);

        // get desctiption and remove empty lines from the end
        $desc = $task["description"];
        $desc = rtrim($desc);

        // parse each line of the description, last line to first line.
        // continue as long as the line can be parsed as a command successfully.
        $offset_lbegin = strlen($desc);
        $offset_lend = strlen($desc);
        while($offset_lend > 0) {
            // find beginning of current line
            $offset_lbegin = $this->findLineBeginningRev($desc, $offset_lend - 1);
            // extract line
            $line = substr($desc, $offset_lbegin, $offset_lend - $offset_lbegin);
            // parse line
            $result = $this->parseLine($line, $task);
            // if successful, continue parsing lines. Stop otherwise.
            if ($result === false) {
                break;
            }
            // make sure offset_lend is always >= 0, otherwise $remaining_desc
            // will be wrong
            $offset_lend = max($offset_lbegin - 2, 0);
        }

        // subtasks could not be added yet (they would be in reverse order).
        // We buffered them in $task["new_subtasks"]. Commit them now.
        if (array_key_exists("new_subtasks", $task)) {
            for ($i = sizeof($task["new_subtasks"]) - 1; $i >= 0; $i--) {
                $this->subtaskModel->create($task["new_subtasks"][$i]);
            }
            unset($task["new_subtasks"]);
        }

        // remove commands from the end of the description
        $task["description"] = substr($desc, 0, $offset_lend);

        // commit updated task array to database
        $this->taskModificationModel->update($task);
    }

    /**
     * Parse a line containing a command. A command starts with a backslash, followed
     * by a keyword and (optinally) a parameter.
     *
     * @param      string  $line   The line
     *
     * @return     bool    true if the line could be parsed successfully. false otherwise.
     */
    private function parseLine(string $line, array &$task)
    {
        // to parse a command, we expect that the line starts with a backslash and a keyword.
        $len = strlen($line);
        if ($len < 2 || $line[0] !== "\\") { return false; }
        
        // after the backslash, we expect a keyword, followed by either space and parameter
        // or end of line.
        // search for the next space (if any)
        $next_space = strpos($line, " ", 1);
        // in any case, $end_of_keyword shall point to the position after the last keyword character
        $end_of_keyword = $next_space;
        if ($next_space === false) {
            $end_of_keyword = $len;
        }
        // extract keyword and parameter (if any)
        $keyword = substr($line, 1, $end_of_keyword - 1);
        $parameter = substr($line, $end_of_keyword + 1);

        // process keyword and parameter
        switch($keyword) {
            case "t":
            case "tag":
            case "tags":
                // mandatory parameter: space-separated list of tags to add
                if ($parameter == '') { return false; }
                // create array of tags from parameter
                $tags = explode(" ", $parameter);
                // query existing tag list; returns array
                $existing_tags = $this->taskTagModel->getList($task["id"]);
                // merge both arrays
                $tags_merged = array_merge($tags, $existing_tags);
                $this->taskTagModel->save($task["project_id"], $task["id"], $tags_merged);
                return true;
            case "s":
            case "st":
            case "sub":
                // mandatory parameter: title of subtask
                if ($parameter == '') { return false; }
                if (!array_key_exists("new_subtasks", $task)) {
                    $task["new_subtasks"] = array();
                }
                // we buffer subtasks in $task["new_subtasks"] until we read
                // all command lines from the description. If we created them
                // right now, they would be in reverse order afterwards.
                array_push($task["new_subtasks"], array(
                    "title" => $parameter,
                    "task_id" => $task["id"],
                    "user_id" => $this->userSession->getId()
                ));
                return true;
            case "d":
            case "due":
                // mandatory parameter: due date
                if ($parameter == '') { return false; }
                $parsed_parameter = $this->parseDateTime($parameter);
                if ($parsed_parameter === false) { return false; }
                $task["date_due"] = $parsed_parameter;
                return true;
            case "start":
                // if no parameter, set to now
                if ($parameter == '') {
                    $parameter = "now";
                }
                $parsed_parameter = $this->parseDateTime($parameter);
                if ($parsed_parameter === false) { return false; }
                $task["date_started"] = $parsed_parameter;
                return true;
            case "p":
            case "prio":
                // mandatory parameter: priority. Must be within bounds.
                $project = $this->projectModel->getById($task["project_id"]);
                $prio_min = intval($project['priority_start']);
                $prio_max = intval($project['priority_end']);
                if (
                    $parameter == ''
                    || !is_numeric($parameter)
                    || intval($parameter) < $prio_min
                    || intval($parameter) > $prio_max
                ) {
                    return false;
                }
                $task["priority"] = $parameter;
                return true;
            case "c":
            case "col":
            case "color":
                // mandatory parameter: color name/id
                if ($parameter == '') { return false; }
                $color_id = $this->colorModel->find($parameter);
                if ($color_id == '') { return false; }
                $task['color_id'] = $color_id;
                return true;
            default:
                return false;
        }
    }

    /**
     * Reverse-iterates over the string until the sequence "\r\n" appears.
     *
     * @param      string  $str     The string to iterate over
     * @param      int     $offset  The offset to start the backwards iteration from
     *
     * @return     int     position of the character before the newline sequence
     * (example: `findLineBeginningRev("foo\r\nbar", 7)` returns 5 (`foo\r\n|bar`).
     */
    private function findLineBeginningRev(string $str, int $offset)
    {
        for ($i = $offset; $i >= 1; $i--) {
            if ($str[$i] == "\n" && $str[$i-1] == "\r") {
                return $i+1;
            }
        }
        return 0;
    }

    /**
     * Parse parameters of date/time commands.
     *
     * @param      string  $parameter  The command parameter
     *
     * @return     bool|int    returns a timestamp, or false on error.
     */
    private function parseDateTime(string $parameter)
    {
        $parameter = strtolower($parameter);
        
        // many cases can be covered by strtotime.
        // strtotime returns false on error, which is fine for us.
        switch($parameter) {
            // 1. now
            case "now":
                return strtotime("now");

            // 2. tomorrow
            case "tm":
            case "tom":
            case "tomorrow":
                return strtotime("tomorrow");

            // 3. day of week
            case "mo":
            case "mon":
            case "monday":
                return strtotime("next Monday");
            case "tu":
            case "tue":
            case "tuesday":
                return strtotime("next Tuesday");
            case "we":
            case "wed":
            case "wednesday":
                return strtotime("next Wednesday");
            case "th":
            case "thu":
            case "thursday":
                return strtotime("next Thursday");
            case "fr":
            case "fri":
            case "friday":
                return strtotime("next Friday");
            case "sa":
            case "sat":
            case "saturday":
                return strtotime("next Saturday");
            case "su":
            case "sun":
            case "sunday":
                return strtotime("next Sunday");
        }
        
        // 3. day of month (1..31)
        if (is_numeric($parameter)) {
            $dom = intval($parameter);
            if ($dom < 1 || $dom > 31) { return false; }
            $current_dom = date("d");
            $current_month = date("Y-m");
            $next_month = date("Y-m", strtotime("first day of next month"));
            $no_days_this_month = date("t", strtotime("last day of this month"));
            $no_days_next_month = date("t", strtotime("last day of next month"));

            if ($dom >= $current_dom) {
                if ($dom > $no_days_this_month) { return false; }
                return strtotime("$current_month-$dom");
            } else {
                if ($dom > $no_days_next_month) { return false; }
                return strtotime("$next_month-$dom");
            }
        }

        // 3. Xd (1d, 2d, ...)
        $result_preg = preg_match("/^\+?(\d+)d$/", $parameter, $matches);
        if ($result_preg == 1) {
            $timestamp = strtotime("+$matches[1] days");
            if ($timestamp === false) { return false; }
            return $this->dateParser->removeTimeFromTimestamp($timestamp);
        }

        // fallback: pass it through strtotime
        return strtotime($parameter);
    }

    public function getPluginName()
    {
        return 'Properties from Description';
    }

    public function getPluginDescription()
    {
        return t('Extracts task properties from the description text.');
    }

    public function getPluginAuthor()
    {
        return 'Till Schlueter';
    }

    public function getPluginVersion()
    {
        return '1.0.0';
    }

    public function getPluginHomepage()
    {
        return 'https://github.com/rfde/kanboard-plugin-propertiesfromdescription';
    }
}