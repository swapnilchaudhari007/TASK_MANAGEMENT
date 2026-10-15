# Task Management System

A PHP + MySQL web app for running team projects: create projects, break them into tasks, assign people, log progress and pull reports.

## Features
- **Projects** – create, edit and track status, dates and assigned members
- **Tasks** – add tasks per project, update status, view details
- **Progress logs** – members record work done against each task (`user_productivity`)
- **Reports** – per-project progress and time summaries
- **Users & roles** – Admin (manages users, projects, settings) and Staff (works on assigned tasks)
- **System settings** – app name, contact info and logo

## Stack
PHP (mysqli, prepared statements) · MySQL / MariaDB · Bootstrap / AdminLTE · jQuery

## Setup (XAMPP / LAMP)
1. Copy this folder into your web root (e.g. `htdocs/TASK_MANAGEMENT`).
2. Create a database named `tms_db` and import `database/tms_db.sql`.
3. Set DB credentials as environment variables (defaults suit local XAMPP):

   | Variable | Default |
   |---|---|
   | `TMS_DB_HOST` | `localhost` |
   | `TMS_DB_USER` | `root` |
   | `TMS_DB_PASS` | *(empty)* |
   | `TMS_DB_NAME` | `tms_db` |

4. Open `http://localhost/TASK_MANAGEMENT/` and sign in with the admin account from the SQL dump. Change its password right away.

## Project layout
| Path | Purpose |
|---|---|
| `index.php`, `login.php` | Entry point and login |
| `admin_class.php`, `ajax.php` | All create/update/delete actions |
| `*_list.php`, `view_*.php`, `new_*.php`, `edit_*.php` | Pages for projects, tasks and users |
| `reports.php` | Reports |
| `database/tms_db.sql` | Schema + seed data |
| `assets/` | CSS/JS plugins and uploaded avatars |

## License
Public domain ([Unlicense](LICENSE)).
