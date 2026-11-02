<?php
declare(strict_types=1);

/**
 * Brings the database up to the current schema on the first request after a
 * deploy — no SQL import needed. Each step runs once; app_settings remembers
 * the version reached. Works on MySQL and MariaDB (columns are checked in
 * information_schema rather than with MariaDB-only "ADD COLUMN IF NOT EXISTS").
 */
const SCHEMA_VERSION = 1;

function runMigrations(PDO $db): void
{
    try {
        $v = (int) $db->query("SELECT v FROM app_settings WHERE k = 'schema_version'")->fetchColumn();
    } catch (PDOException) {
        $v = 0;
    }
    if ($v >= SCHEMA_VERSION) {
        seedAdmin($db);
        return;
    }

    // Two requests can arrive together right after a deploy; let one migrate.
    if ((int) $db->query("SELECT GET_LOCK('workfinder_migrate', 10)")->fetchColumn() !== 1) {
        return;
    }
    try {
        if ($v < 1) migrationV1($db);
        $db->prepare("REPLACE INTO app_settings (k, v) VALUES ('schema_version', ?)")->execute([(string) SCHEMA_VERSION]);
    } finally {
        $db->query("SELECT RELEASE_LOCK('workfinder_migrate')");
    }
    seedAdmin($db);
}

function columnExists(PDO $db, string $table, string $column): bool
{
    $s = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $s->execute([$table, $column]);
    return (bool) $s->fetchColumn();
}

function addColumn(PDO $db, string $table, string $column, string $definition): void
{
    if (!columnExists($db, $table, $column)) {
        $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }
}

/** Every table the app needs, plus the starter industries and skills dictionary. */
function migrationV1(PDO $db): void
{
    $t = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $db->exec("CREATE TABLE IF NOT EXISTS app_settings (
        k          VARCHAR(100) PRIMARY KEY,
        v          TEXT,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ){$t}");

    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id               INT PRIMARY KEY AUTO_INCREMENT,
        email            VARCHAR(255) UNIQUE NOT NULL,
        password_hash    VARCHAR(255) NULL,
        role             ENUM('user','admin') DEFAULT 'user',
        is_active        TINYINT(1) DEFAULT 1,
        full_name        VARCHAR(150),
        phone            VARCHAR(50),
        google_id        VARCHAR(64) NULL,
        avatar_url       VARCHAR(500) NULL,
        headline         VARCHAR(200),
        job_titles       VARCHAR(500),
        location         VARCHAR(120),
        province         VARCHAR(60),
        experience_level ENUM('entry','junior','mid','senior','lead') NULL,
        salary_min       INT NULL,
        salary_max       INT NULL,
        work_types       VARCHAR(100),
        bio              TEXT,
        settings         JSON,
        daily_email_limit INT NULL,
        onboarded_at     DATETIME NULL,
        signup_method    VARCHAR(20) DEFAULT 'email',
        signup_source    VARCHAR(255),
        signup_ip        VARCHAR(45),
        popia_consent_at DATETIME NULL,
        last_login_at    DATETIME NULL,
        login_count      INT DEFAULT 0,
        created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_google (google_id),
        INDEX idx_created (created_at)
    ){$t}");

    $db->exec("CREATE TABLE IF NOT EXISTS industries (
        id   INT PRIMARY KEY AUTO_INCREMENT,
        name VARCHAR(120) NOT NULL,
        slug VARCHAR(120) NOT NULL UNIQUE,
        icon VARCHAR(40) DEFAULT 'fa-briefcase'
    ){$t}");

    $db->exec("CREATE TABLE IF NOT EXISTS skills (
        id       INT PRIMARY KEY AUTO_INCREMENT,
        name     VARCHAR(100) NOT NULL,
        slug     VARCHAR(100) NOT NULL UNIQUE,
        category VARCHAR(60) DEFAULT 'General'
    ){$t}");

    $db->exec("CREATE TABLE IF NOT EXISTS skill_synonyms (
        id       INT PRIMARY KEY AUTO_INCREMENT,
        skill_id INT NOT NULL,
        alias    VARCHAR(100) NOT NULL UNIQUE,
        FOREIGN KEY (skill_id) REFERENCES skills(id) ON DELETE CASCADE
    ){$t}");

    $db->exec("CREATE TABLE IF NOT EXISTS companies (
        id          INT PRIMARY KEY AUTO_INCREMENT,
        name        VARCHAR(200) NOT NULL,
        slug        VARCHAR(200) NOT NULL UNIQUE,
        industry_id INT NULL,
        location    VARCHAR(120),
        province    VARCHAR(60),
        size        VARCHAR(30),
        website     VARCHAR(300),
        careers_url VARCHAR(300),
        hr_email    VARCHAR(255),
        description TEXT,
        is_verified TINYINT(1) DEFAULT 0,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (industry_id) REFERENCES industries(id) ON DELETE SET NULL,
        INDEX idx_industry (industry_id),
        INDEX idx_province (province)
    ){$t}");

    $db->exec("CREATE TABLE IF NOT EXISTS jobs (
        id           INT PRIMARY KEY AUTO_INCREMENT,
        source       VARCHAR(30) NOT NULL DEFAULT 'manual',
        external_id  VARCHAR(120) NULL,
        title        VARCHAR(250) NOT NULL,
        company_id   INT NULL,
        company_name VARCHAR(200),
        industry_id  INT NULL,
        location     VARCHAR(150),
        province     VARCHAR(60),
        is_remote    TINYINT(1) DEFAULT 0,
        work_type    ENUM('full_time','part_time','contract','internship','temporary') DEFAULT 'full_time',
        experience_level ENUM('entry','junior','mid','senior','lead') NULL,
        salary_min   INT NULL,
        salary_max   INT NULL,
        description  MEDIUMTEXT,
        apply_url    VARCHAR(600),
        apply_email  VARCHAR(255),
        posted_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        expires_at   DATETIME NULL,
        is_featured  TINYINT(1) DEFAULT 0,
        is_active    TINYINT(1) DEFAULT 1,
        dedupe_hash  CHAR(40) NOT NULL UNIQUE,
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
        FOREIGN KEY (industry_id) REFERENCES industries(id) ON DELETE SET NULL,
        INDEX idx_active_posted (is_active, posted_at),
        INDEX idx_company (company_id),
        INDEX idx_industry (industry_id),
        INDEX idx_province (province),
        INDEX idx_source (source),
        FULLTEXT ft_jobs (title, description)
    ){$t}");

    $db->exec("CREATE TABLE IF NOT EXISTS job_skills (
        job_id   INT NOT NULL,
        skill_id INT NOT NULL,
        PRIMARY KEY (job_id, skill_id),
        INDEX idx_skill (skill_id),
        FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
        FOREIGN KEY (skill_id) REFERENCES skills(id) ON DELETE CASCADE
    ){$t}");

    $db->exec("CREATE TABLE IF NOT EXISTS user_skills (
        user_id  INT NOT NULL,
        skill_id INT NOT NULL,
        PRIMARY KEY (user_id, skill_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (skill_id) REFERENCES skills(id) ON DELETE CASCADE
    ){$t}");

    $db->exec("CREATE TABLE IF NOT EXISTS user_industries (
        user_id     INT NOT NULL,
        industry_id INT NOT NULL,
        PRIMARY KEY (user_id, industry_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (industry_id) REFERENCES industries(id) ON DELETE CASCADE
    ){$t}");

    $db->exec("CREATE TABLE IF NOT EXISTS job_sources (
        code        VARCHAR(30) PRIMARY KEY,
        name        VARCHAR(80) NOT NULL,
        enabled     TINYINT(1) DEFAULT 1,
        last_run_at DATETIME NULL,
        last_status VARCHAR(500),
        last_count  INT DEFAULT 0
    ){$t}");

    $db->exec("CREATE TABLE IF NOT EXISTS cvs (
        id            INT PRIMARY KEY AUTO_INCREMENT,
        user_id       INT NOT NULL,
        file_name     VARCHAR(120) NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        mime          VARCHAR(120),
        size          INT,
        is_default    TINYINT(1) DEFAULT 0,
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ){$t}");

    $db->exec("CREATE TABLE IF NOT EXISTS saved_jobs (
        user_id    INT NOT NULL,
        job_id     INT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, job_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE
    ){$t}");

    $db->exec("CREATE TABLE IF NOT EXISTS followed_companies (
        user_id    INT NOT NULL,
        company_id INT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, company_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
    ){$t}");

    $db->exec("CREATE TABLE IF NOT EXISTS applications (
        id           INT PRIMARY KEY AUTO_INCREMENT,
        user_id      INT NOT NULL,
        job_id       INT NULL,
        company_id   INT NULL,
        title        VARCHAR(250) NOT NULL,
        company_name VARCHAR(200),
        status       ENUM('saved','applied','interview','offer','rejected') DEFAULT 'saved',
        notes        TEXT,
        follow_up_on DATE NULL,
        applied_at   DATETIME NULL,
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE SET NULL,
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
        INDEX idx_user_status (user_id, status)
    ){$t}");

    $db->exec("CREATE TABLE IF NOT EXISTS sent_emails (
        id              INT PRIMARY KEY AUTO_INCREMENT,
        user_id         INT NULL,
        application_id  INT NULL,
        kind            ENUM('application','copy','alert','system') NOT NULL DEFAULT 'system',
        to_email        VARCHAR(255) NOT NULL,
        subject         VARCHAR(300),
        body            MEDIUMTEXT,
        attachment_name VARCHAR(255),
        status          ENUM('sent','failed','bounced') NOT NULL DEFAULT 'sent',
        error           TEXT,
        open_token      CHAR(32) NULL,
        open_count      INT DEFAULT 0,
        opened_at       DATETIME NULL,
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE SET NULL,
        INDEX idx_user_kind (user_id, kind, created_at),
        INDEX idx_to (to_email),
        INDEX idx_token (open_token)
    ){$t}");

    $db->exec("CREATE TABLE IF NOT EXISTS saved_searches (
        id           INT PRIMARY KEY AUTO_INCREMENT,
        user_id      INT NOT NULL,
        name         VARCHAR(150) NOT NULL,
        query        JSON,
        frequency    ENUM('daily','weekly','off') DEFAULT 'daily',
        last_sent_at DATETIME NULL,
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ){$t}");

    $db->exec("CREATE TABLE IF NOT EXISTS email_optouts (
        email      VARCHAR(255) PRIMARY KEY,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ){$t}");

    $db->exec("CREATE TABLE IF NOT EXISTS audit_log (
        id         INT PRIMARY KEY AUTO_INCREMENT,
        admin_id   INT NULL,
        action     VARCHAR(60) NOT NULL,
        target     VARCHAR(200),
        detail     TEXT,
        ip         VARCHAR(45),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_created (created_at)
    ){$t}");

    $db->exec("CREATE TABLE IF NOT EXISTS password_resets (
        id         INT PRIMARY KEY AUTO_INCREMENT,
        user_id    INT NOT NULL,
        token_hash CHAR(64) NOT NULL UNIQUE,
        expires_at DATETIME NOT NULL,
        used_at    DATETIME NULL,
        ip         VARCHAR(45),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ){$t}");

    $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id         INT PRIMARY KEY AUTO_INCREMENT,
        email      VARCHAR(255),
        ip         VARCHAR(45),
        success    TINYINT(1) DEFAULT 0,
        method     VARCHAR(20) DEFAULT 'password',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_email (email, created_at),
        INDEX idx_ip (ip, created_at)
    ){$t}");

    seedDictionary($db);

    $src = $db->prepare('INSERT IGNORE INTO job_sources (code, name, enabled) VALUES (?, ?, ?)');
    foreach ([['adzuna', 'Adzuna (South Africa)', 1], ['careerjet', 'Careerjet', 1], ['jooble', 'Jooble', 1], ['remotive', 'Remotive (remote jobs)', 1], ['arbeitnow', 'Arbeitnow (remote / EU)', 0]] as $row) {
        $src->execute($row);
    }
}

/** Starter industries, skills and synonyms used for search and skill matching. */
function seedDictionary(PDO $db): void
{
    $industries = [
        ['Information Technology', 'fa-laptop-code'], ['Finance & Banking', 'fa-building-columns'], ['Healthcare', 'fa-stethoscope'],
        ['Engineering', 'fa-gears'], ['Mining', 'fa-helmet-safety'], ['Retail', 'fa-bag-shopping'], ['Education', 'fa-graduation-cap'],
        ['Marketing & Media', 'fa-bullhorn'], ['Logistics & Transport', 'fa-truck-fast'], ['Hospitality & Tourism', 'fa-hotel'],
        ['Manufacturing', 'fa-industry'], ['Construction', 'fa-trowel-bricks'], ['Government & NGO', 'fa-landmark'],
        ['Legal', 'fa-scale-balanced'], ['Telecommunications', 'fa-tower-cell'], ['Energy', 'fa-bolt'], ['Agriculture', 'fa-seedling'],
        ['Call Centre & Customer Service', 'fa-headset'],
    ];
    $s = $db->prepare('INSERT IGNORE INTO industries (name, slug, icon) VALUES (?, ?, ?)');
    foreach ($industries as [$name, $icon]) $s->execute([$name, slugify($name), $icon]);

    $skills = [
        'Software' => ['PHP', 'JavaScript', 'TypeScript', 'Python', 'Java', 'C#', '.NET', 'React', 'Angular', 'Vue', 'Node.js', 'Laravel', 'SQL', 'MySQL', 'PostgreSQL', 'HTML', 'CSS', 'Git', 'AWS', 'Azure', 'Docker', 'Kubernetes', 'Linux', 'DevOps', 'Flutter', 'Kotlin', 'Swift', 'Power BI', 'Excel', 'Data Analysis', 'Machine Learning', 'Cybersecurity', 'Networking', 'IT Support'],
        'Business'  => ['Project Management', 'Sales', 'Marketing', 'Digital Marketing', 'SEO', 'Social Media', 'Accounting', 'Bookkeeping', 'Payroll', 'Pastel', 'Sage', 'SAP', 'Financial Analysis', 'Auditing', 'Tax', 'Human Resources', 'Recruitment', 'Procurement', 'Supply Chain', 'Business Analysis', 'Administration', 'Customer Service', 'Call Centre'],
        'Trades & Ops' => ['Driving', "Driver's Licence Code 10", 'Forklift', 'Electrical', 'Plumbing', 'Welding', 'Mechanical', 'Health & Safety', 'Warehouse', 'Logistics', 'AutoCAD', 'Civil Engineering', 'Nursing', 'Pharmacy', 'Teaching', 'Hospitality', 'Cooking'],
        'People'    => ['Communication', 'Leadership', 'Teamwork', 'Problem Solving', 'Time Management', 'Afrikaans', 'isiZulu', 'isiXhosa', 'Sesotho'],
    ];
    $s = $db->prepare('INSERT IGNORE INTO skills (name, slug, category) VALUES (?, ?, ?)');
    foreach ($skills as $cat => $names) foreach ($names as $n) $s->execute([$n, slugify($n), $cat]);

    $syn = ['javascript' => ['js', 'ecmascript'], 'typescript' => ['ts'], 'node-js' => ['nodejs', 'node'], 'react' => ['react.js', 'reactjs'],
            'vue' => ['vue.js', 'vuejs'], 'postgresql' => ['postgres'], 'aws' => ['amazon web services'], 'machine-learning' => ['ml', 'ai'],
            'human-resources' => ['hr'], 'customer-service' => ['customer support'], 'driver-s-licence-code-10' => ['code 10', 'code 08', 'drivers licence', "driver's license"],
            'excel' => ['ms excel', 'microsoft excel'], 'power-bi' => ['powerbi'], 'seo' => ['search engine optimisation', 'search engine optimization']];
    $find = $db->prepare('SELECT id FROM skills WHERE slug = ?');
    $add  = $db->prepare('INSERT IGNORE INTO skill_synonyms (skill_id, alias) VALUES (?, ?)');
    foreach ($syn as $slug => $aliases) {
        $find->execute([$slug]);
        if ($id = $find->fetchColumn()) foreach ($aliases as $a) $add->execute([$id, $a]);
    }
}

function slugify(string $s): string
{
    $s = strtolower(trim($s));
    $s = str_replace(['#', '+', '&'], ['sharp', 'plus', 'and'], $s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim((string) $s, '-') ?: 'x';
}

/**
 * Creates admin@ (ADMIN_EMAIL) from admin_seed_password in secrets.php the
 * first time, and keeps that account an active admin afterwards.
 */
function seedAdmin(PDO $db): void
{
    $email = ADMIN_EMAIL;
    $s = $db->prepare('SELECT id, password_hash, role, is_active FROM users WHERE email = ?');
    $s->execute([$email]);
    $row  = $s->fetch();
    $seed = (string) secret('admin_seed_password', '');

    if (!$row) {
        if ($seed === '' || $seed === 'CHANGE_ME') {
            return;
        }
        $db->prepare("INSERT INTO users (email, full_name, password_hash, role, is_active, settings, signup_method, onboarded_at)
                      VALUES (?, 'Administrator', ?, 'admin', 1, '{}', 'seed', NOW())")
           ->execute([$email, password_hash($seed, PASSWORD_DEFAULT)]);
        return;
    }
    if ($row['role'] !== 'admin' || !(int) $row['is_active']) {
        $db->prepare("UPDATE users SET role = 'admin', is_active = 1 WHERE id = ?")->execute([$row['id']]);
    }
    if (empty($row['password_hash']) && $seed !== '' && $seed !== 'CHANGE_ME') {
        $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($seed, PASSWORD_DEFAULT), $row['id']]);
    }
}
