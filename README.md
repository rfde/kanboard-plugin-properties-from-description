Properties from Description
==============================

Extracts task properties from the description text:

![Screenshot: Description text with commands](https://i.imgur.com/BVdRFkL.png)

![Screenshot: Resulting card (board view)](https://i.imgur.com/yLpYj3b.png)

[![Screenshot: Resulting card (detail view)](https://i.imgur.com/5djRk3ql.png)](https://i.imgur.com/5djRk3q.png)

You can add the following commands to the **end of the description**:

| Property | Command | Shortcuts | Example |
|----------|---------|-----------|---------|
| Due date | `\due` | `\d` | `\due <timestamp>` |
| Start date | `\start` | | `\start <timestamp>` |
| Tags | `\tags` | `\tag`, `\t` | `\tags foo bar baz` |
| Subtask | `\sub` | `\st`, `\s` | `\sub This is the title` |
| Priority | `\prio` | `\p` | `\prio 3` |
| Color | `\color` | `\col`, `\color` | `\color <color_id>` |

Possible `<timestamp>`s are:

| Timestamp | Description |
|-----------|-------------|
| `tomorrow`, `tom`, `tm` | Next day, 00:00 |
| `monday`, `mon`, `mo` etc. | Next monday (from now) etc., 00:00 |
| `1d`, `2d`, etc. | One days, two days, etc. from now, 00:00 |
| `1`, `2`, ..., `31` | Next time this day of month appears from now, 00:00 |

Further, you can pass any string that is accepted by [`strtotime()`](https://www.php.net/manual/de/function.strtotime.php).

Kanboard's [default](https://github.com/kanboard/kanboard/blob/master/app/Model/ColorModel.php) `color_id`s are: `yellow`, `blue`, `green`, `purple`, `red`, `orange`, `grey`, `brown`, `deep_orange`, `dark_grey`, `pink`, `teal`, `cyan`, `lime`, `light_green`, `amber`.

Author
------

- Till Schlueter
- License MIT

Requirements
------------

- Kanboard >= 1.2.19

Installation
------------

You have the choice between 3 methods:

1. Install the plugin from the Kanboard plugin manager in one click
2. Download the zip file and decompress everything under the directory `plugins/PropertiesFromDescription`
3. Clone this repository into the folder `plugins/PropertiesFromDescription`

Note: Plugin folder is case-sensitive.
