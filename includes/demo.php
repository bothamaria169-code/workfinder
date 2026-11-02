<?php
declare(strict_types=1);

require_once __DIR__ . '/jobs.php';

/**
 * Sample companies and jobs so the app can be tried before any job API key
 * is set. Demo rows are marked source = 'demo' and can be removed in one click.
 * Emails use example.co.za addresses, which never deliver.
 *
 * @return int jobs added
 */
function loadDemoData(): int
{
    $db = getDB();
    $ind = fn(string $slug) => (int) $db->query('SELECT id FROM industries WHERE slug = ' . $db->quote($slug))->fetchColumn() ?: null;
    $companies = [
        ['Ndlovu Digital Labs', 'information-technology', 'Rosebank, Johannesburg', '201-500'], ['Kopano Software', 'information-technology', 'Centurion', '51-200'],
        ['Umoya Fintech', 'finance-and-banking', 'Sandton, Johannesburg', '51-200'], ['Karoo Foods', 'retail', 'Bellville, Cape Town', '501-1000'],
        ['Ithemba Health Group', 'healthcare', 'Durban', '1000+'], ['Baobab Logistics', 'logistics-and-transport', 'Kempton Park', '201-500'],
        ['Fynbos Marketing Co', 'marketing-and-media', 'Woodstock, Cape Town', '11-50'], ['Highveld Engineering', 'engineering', 'Pretoria', '201-500'],
        ['Lekker Hospitality', 'hospitality-and-tourism', 'Umhlanga', '51-200'], ['Ubuntu Call Solutions', 'call-centre-and-customer-service', 'Gqeberha', '501-1000'],
        ['Thuso Accounting', 'finance-and-banking', 'Bloemfontein', '11-50'], ['Platinum Ridge Mining', 'mining', 'Rustenburg', '1000+'],
        ['Masakhane Schools Trust', 'education', 'Polokwane', '51-200'], ['Indaba Energy', 'energy', 'Mbombela', '201-500'],
        ['Aloe Build Group', 'construction', 'Midrand', '201-500'], ['Sizwe Legal', 'legal', 'Cape Town CBD', '11-50'],
        ['Jacaranda Retail', 'retail', 'Menlyn, Pretoria', '1000+'], ['Kasi Connect Telecoms', 'telecommunications', 'Randburg', '201-500'],
        ['Amandla Manufacturing', 'manufacturing', 'Germiston', '501-1000'], ['Remote First Studio', 'information-technology', 'Remote', '11-50'],
    ];
    $coIds = [];
    $s = $db->prepare('INSERT IGNORE INTO companies (name, slug, industry_id, location, province, size, website, hr_email, description, is_verified) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)');
    foreach ($companies as [$name, $slug, $loc, $size]) {
        $cs = slugify($name);
        $s->execute([$name, $cs, $ind($slug), $loc, provinceFor($loc), $size, 'https://' . $cs . '.example.co.za', 'careers@' . $cs . '.example.co.za',
                     "{$name} is a sample company used to demonstrate WorkFinder. Replace it with real companies in Admin → Companies."]);
        $coIds[$name] = (int) $db->query('SELECT id FROM companies WHERE slug = ' . $db->quote($cs))->fetchColumn();
    }

    $roles = [
        ['Junior PHP Developer', 'information-technology', 'We need a junior developer comfortable with PHP, MySQL, HTML, CSS and JavaScript. Laravel and Git experience is a plus.', 18000, 26000],
        ['Senior React Developer', 'information-technology', 'Lead our front-end team. Strong React, TypeScript, Node.js and AWS skills. Mentoring and communication are key.', 55000, 75000],
        ['IT Support Technician', 'information-technology', 'First-line IT support: Windows, networking, Microsoft 365 and customer service. Driver\'s licence Code 10 required.', 12000, 17000],
        ['Data Analyst', 'information-technology', 'Turn data into decisions using SQL, Excel and Power BI. Python is advantageous. Problem solving and communication skills essential.', 30000, 42000],
        ['DevOps Engineer', 'information-technology', 'Own our CI/CD on Azure with Docker, Kubernetes and Linux. Scripting in Python.', 50000, 70000],
        ['Bookkeeper', 'finance-and-banking', 'Full bookkeeping to trial balance on Sage and Pastel, payroll for 40 staff, VAT returns. Excel proficiency required.', 15000, 22000],
        ['Financial Analyst', 'finance-and-banking', 'Budgeting, forecasting and financial analysis. Advanced Excel, SAP and Power BI. CA(SA) articles completed preferred.', 40000, 55000],
        ['Credit Controller', 'finance-and-banking', 'Manage debtors, collections and customer queries. Accounting background, Excel and strong communication.', 14000, 19000],
        ['Professional Nurse', 'healthcare', 'Registered professional nurse for a busy ward. SANC registration, teamwork and time management.', 28000, 36000],
        ['Pharmacist Assistant', 'healthcare', 'Post-basic pharmacist assistant for a retail pharmacy. Customer service and attention to detail.', 11000, 15000],
        ['Code 10 Delivery Driver', 'logistics-and-transport', 'Deliver to clients across Gauteng. Valid Driver\'s Licence Code 10 and PrDP. Good knowledge of the area.', 9000, 12000],
        ['Warehouse Supervisor', 'logistics-and-transport', 'Supervise a team of 15 in a distribution warehouse. Forklift licence, logistics and leadership experience.', 16000, 22000],
        ['Supply Chain Coordinator', 'logistics-and-transport', 'Coordinate procurement and supply chain planning. SAP and Excel. Problem solving.', 22000, 30000],
        ['Digital Marketing Specialist', 'marketing-and-media', 'Run campaigns across social media, SEO and Google Ads. Content creation and digital marketing analytics.', 22000, 32000],
        ['Social Media Intern', 'marketing-and-media', '12-month internship for a graduate passionate about social media, content and marketing.', 6000, 8000],
        ['Mechanical Engineer', 'engineering', 'Design and maintain plant equipment. BSc Mechanical Engineering, AutoCAD, health & safety. ECSA registration advantageous.', 45000, 60000],
        ['Electrician', 'engineering', 'Qualified electrician with trade test. Electrical installations, maintenance and fault finding. Health & safety.', 18000, 25000],
        ['Chef de Partie', 'hospitality-and-tourism', 'Run a section in a busy hotel kitchen. Cooking experience, hospitality, teamwork under pressure.', 12000, 16000],
        ['Front Office Agent', 'hospitality-and-tourism', 'Welcome guests, manage bookings and handle queries. Customer service, Afrikaans or isiZulu an advantage.', 9000, 12000],
        ['Call Centre Agent (Inbound)', 'call-centre-and-customer-service', 'Handle inbound customer service calls. Matric, good communication, isiXhosa or Afrikaans an advantage. Training provided.', 7500, 9500],
        ['Team Leader – Call Centre', 'call-centre-and-customer-service', 'Lead a team of 12 agents. Call centre, leadership and time management experience required.', 14000, 18000],
        ['Mining Engineer', 'mining', 'Underground mine planning and production. Mine Manager\'s Certificate advantageous. Health & safety focus.', 60000, 85000],
        ['Grade 4 Teacher', 'education', 'SACE-registered Foundation/Intermediate phase teacher. Teaching, communication and patience.', 18000, 24000],
        ['Site Manager', 'construction', 'Manage residential construction sites. Project management, health & safety and civil engineering background.', 35000, 48000],
        ['Paralegal', 'legal', 'Support attorneys with research, drafting and administration. Legal qualification and excellent writing.', 14000, 20000],
        ['Retail Store Manager', 'retail', 'Run a high-volume store: sales targets, staff, stock and customer service. Leadership experience.', 20000, 28000],
        ['Cashier', 'retail', 'Process payments and assist customers. Matric, customer service and numeracy.', 5000, 7000],
        ['Network Engineer', 'telecommunications', 'Design and support fibre and wireless networks. Networking certifications (CCNA), Linux and problem solving.', 35000, 50000],
        ['Production Operator', 'manufacturing', 'Operate production line machinery on shifts. Mechanical aptitude, health & safety and teamwork.', 7000, 9500],
        ['Remote Full-stack Developer', 'information-technology', 'Fully remote role. Vue, Laravel, PHP, PostgreSQL and Docker. Strong written communication.', 45000, 65000],
        ['HR Generalist', 'finance-and-banking', 'End-to-end human resources: recruitment, payroll input and employee relations. Communication and administration.', 22000, 30000],
        ['Project Manager – Energy', 'energy', 'Manage solar installation projects. Project management, electrical background and stakeholder communication.', 45000, 60000],
    ];
    $types = ['full_time', 'full_time', 'full_time', 'contract', 'part_time'];
    $added = 0;
    $names = array_keys($coIds);
    foreach ($roles as $i => [$title, $slug, $desc, $min, $max]) {
        for ($k = 0; $k < 3; $k++) {
            $co = $names[($i * 3 + $k * 7) % count($names)];
            if ($k > 0 && ($co === 'Remote First Studio') !== str_contains($title, 'Remote')) continue;
            $loc = (string) $db->query('SELECT location FROM companies WHERE id = ' . $coIds[$co])->fetchColumn();
            if (str_contains($title, 'Remote')) { $co = 'Remote First Studio'; $loc = 'Remote'; }
            $ok = upsertJob([
                'source' => 'demo', 'title' => $title, 'company_name' => $co, 'industry_id' => $ind($slug), 'location' => $loc,
                'description' => $desc . "\n\nAbout the role:\n• Join a supportive team at {$co}.\n• Market-related salary and benefits.\n\nTo apply, email your CV to careers@" . slugify($co) . '.example.co.za.',
                'apply_email' => 'careers@' . slugify($co) . '.example.co.za', 'salary_min' => $min, 'salary_max' => $max,
                'work_type' => str_contains($title, 'Intern') ? 'internship' : $types[($i + $k) % count($types)], 'is_remote' => str_contains($title, 'Remote'),
                'posted_at' => date('Y-m-d H:i:s', strtotime('-' . (($i * 5 + $k * 11) % 26) . ' days -' . ($i % 9) . ' hours')),
                'is_featured' => $i % 11 === 0 && $k === 0,
            ]);
            if ($ok) $added++;
        }
    }
    return $added;
}

function removeDemoData(): int
{
    $db = getDB();
    $n = $db->exec("DELETE FROM jobs WHERE source = 'demo'");
    $db->exec("DELETE FROM companies WHERE website LIKE '%.example.co.za' AND NOT EXISTS (SELECT 1 FROM jobs j WHERE j.company_id = companies.id)");
    return (int) $n;
}
