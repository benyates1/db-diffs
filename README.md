🔄 Database Schema Syncer
A lightweight, dependency-free PHP 8.4 application that compares two MySQL or MariaDB databases (e.g., Development vs. Production) and generates clean, non-destructive SQL migration scripts to keep your database structures perfectly aligned.

Designed for solo developers and small teams, this tool acts as a drop-in alternative to heavy desktop clients (like DataGrip or MySQL Workbench) for generating structural diffs instantly in the browser.

✨ Key Features
Deep Structural Diffing: Compares Tables, Columns (data types, lengths, defaults, nullability), Indexes, and Foreign Keys.

Smart Rename Detection: Intelligently detects renamed columns using ordinal positioning and 1-to-1 missing/ignored column ratios, safely generating ALTER TABLE ... CHANGE statements instead of leaving orphaned columns.

Foreign Key Synchronization: Fully maps and diffs relational constraints (including composite keys, ON UPDATE, and ON DELETE rules).

Cross-Engine Compatibility: Automatically sanitizes MySQL 8+ syntax (like utf8mb4_0900_ai_ci collations and VISIBLE index keywords) so scripts run flawlessly on older MariaDB servers.

Granular SQL Exports: Generates a complete "Master Migration Script" as well as specific, per-table SQL snippets via a clean modal UI.

Database Stats Dashboard: Instantly view engine versions, total database sizes, and table counts for both source and target environments.

Zero Dependencies: A single index.php file styled with Tailwind CSS (via CDN). No Composer, no build steps, no heavy frameworks.

🛡️ The "Do No Harm" Policy
This tool strictly adheres to a Safe Update Mode:

Structural Only: It does not read, export, or touch your actual table data.

No Drops: It will never generate a DROP TABLE or DROP COLUMN command. Columns that exist in the target database but not in the source are simply ignored to guarantee zero accidental data loss.

🚀 Requirements
PHP 8.0+ (Tested on PHP 8.4)

PDO MySQL Extension enabled

MySQL 5.7+ or MariaDB 10.2+

🛠️ Installation & Usage
Clone or download this repository.

Drop the index.php file into your local web server directory (e.g., XAMPP, MAMP, Laravel Valet, or a standard Apache/Nginx setup).

Open the file in your browser.

Enter the database credentials for your Source (Dev) and Target (Prod) databases.

Click Run Analysis & Generate Scripts.

Review the discrepancies, copy your SQL scripts, and run them against your production database using your preferred database manager!

💡 Use Cases
Pushing local development schema changes to a staging or production server.

Verifying that a production database matches the current state of your local blueprint.

Quickly generating ALTER TABLE statements without writing them manually.

Note: Always remember to manually back up your production database using your preferred tool (like mysqldump) before running any generated structural migration scripts.