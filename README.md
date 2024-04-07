# PKP Preservation Network Plugin for OJS

[![OJS compatibility](https://img.shields.io/badge/ojs-3.4-brightgreen)](https://github.com/pkp/ojs/tree/stable-3_4_0)
[![GitHub release](https://img.shields.io/github/v/release/pkp/pln?include_prereleases&label=latest%20release&filter=v3*)](https://github.com/pkp/pln/releases)
![GitHub code size in bytes](https://img.shields.io/github/languages/code-size/pkp/pln)
[![License type](https://img.shields.io/github/license/pkp/pln)](https://github.com/pkp/pln/blob/main/LICENSE)
[![Number of downloads](https://img.shields.io/github/downloads/pkp/pln/total)](https://github.com/pkp/pln/releases)
[![Commit activity per year](https://img.shields.io/github/commit-activity/y/pkp/pln)](https://github.com/pkp/pln/graphs/code-frequency)
[![Contributors](https://img.shields.io/github/contributors-anon/pkp/pln)](https://github.com/pkp/pln/graphs/contributors)

## About

This plugin provides a means for OJS to preserve content in the PKP
Preservation Network (PKP PN). The plugin checks for new and modified content
and provided the PN's terms of use are met, will communicate with the PN's
staging server to preserve your published content automatically.

If you need support for older OJS releases, see the [available branches](https://github.com/pkp/pln/branches).

## Installation Instructions

We recommend installing this plugin using the Plugin Gallery within OJS. Log in
with administrator privileges, navigate to `Settings` > `Website` > `Plugins`, and
choose the Plugin Gallery. Find the `PN Plugin` there and install it.

> If for some reason, you need to install it manually, there are two ways:
> - Download the latest release (attention to the OJS version compatibility)
> - Download the code from GitHub (attention to grab the code from the right branch), then run `composer install` at the main plugin folder.
>
> After downloading, create the folder `plugins/generic/pln` and place the plugin files in it.
>
> Run the command `php lib/pkp/tools/installPluginVersion.php plugins/generic/pln/version.xml` at the main OJS folder, this will ensure the plugin is installed/upgraded properly (e.g. new fields might be added to the database).

After installing and enabling the plugin, you need to access its settings:

![image](https://github.com/pkp/pln/assets/361921/50ea976e-1bd7-41c3-954f-0455ce94787d)

Then read and accept all terms of use, and click on the save button:

![image](https://github.com/pkp/pln/assets/361921/6efac5d8-491f-477a-8bda-dbf7b41b9c09)

## License

This plugin is licensed under the GNU General Public License v3. See the
file LICENSE for the complete terms of this license.

## System Requirements

- OJS 3.4.0 or greater.
- CURL support for PHP.
- ZipArchive support for PHP.

## Note

The primary difference between this plugin and the existing LOCKSS preservation
mechanism present in OJS is the PN requires no registration or involvement with
the network - as long as you agree with the network's terms of use, you can
preserve your journal's content.

## Contact/Support

If you have issues, please use the PKP support forum (https://forum.pkp.sfu.ca/c/questions/5),
the issues tracker (https://github.com/pkp/pln/issues) is reserved for triaged issues.

## Setting up the deposit server

By default, the plugin deposits to https://pkp-pn.lib.sfu.ca. Journal
managers can change the URL on the plugin settings page. The default URL can
also be set in the OJS `config.inc.php` file by adding this configuration:

```
; Change the default Preservation Network URL
[lockss]
pln_url = https://example.com
```

You will need to clear the data caches after adding or changing this setting.
There is a link to clear the caches at
`Site Administration` > `Administration`

## Build Instructions

(These instructions are only necessary if you are working with the plugin
manually. If you are installing the plugin using the Plugin Gallery, they are
not necessary.)

- Clone the repository containing the code.
- Run OJS's `php tools/upgrade.php upgrade`
- Execute `composer install` from console, being in the cloned `pln` folder.
  (This process is going to produce a `vendor` folder containing the depending
  library.)
- Enable Acron plugin and change `config.inc.php` variable `scheduled_tasks = On`
- Enable the PN plugin

## Other useful hints / Troubleshooting hints

- The plugin depends on 2 database tables: `pln_deposits` and `pln_deposit_objects`.
  If those tables are not present in your database, it means the plugin wasn't
  installed properly, refer to the previous sections for help.

- Ensure the plugin is creating daily log files at the `scheduledTaskLogs` folder within
  the OJS files directory. Files named as `PKPPLNDepositorTask-*id*-*datestamp*` should
  be present. If absent, the task is probably not being executed daily or
  there might be permission issues to create them.

- The `APP\plugins\generic\pln\classes\tasks\Depositor` task must be present in the
  `scheduled_tasks` table. If it's not, try to reload the scheduled tasks at the
  Acron plugin (the option `Reload Scheduled Tasks` at the plugin settings).

- Every log file should end with an entry like `[*date time*] [Notice] Task process stopped.`.
  If absent, it means the process has been halted unexpectedly due to errors, check
  the server/PHP error log for more information.

- If an issue fails to be packaged, try to export it through the Native XML plugin at the
  `Tools` > `Import/Export`, which is supposed to display some hints about what went wrong.

- Whenever something doesn't work as expected, always check the error log for clues.
  If nothing helps, report your problem in the forum.

## Original authors

- Chris MacDonald <chris@fcts.ca>
- Michael Joyce <ubermichael@gmail.com>
- Dimitris Efstathiou <defstat@gmail.com>
