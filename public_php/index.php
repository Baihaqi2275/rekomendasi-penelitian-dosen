<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$configFile = __DIR__ . '/config.php';
$config = is_file($configFile) ? require $configFile : [];

function load_sparring_env(string $file): array
{
    if (!is_file($file) || !is_readable($file)) {
        return [];
    }

    $values = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $values[trim($key)] = trim(trim($value), "\"'");
    }

    return $values;
}

function sparring_env_database_config(): array
{
    $env = load_sparring_env(__DIR__ . '/quickstart/.env');
    $mapping = [
        'SPARRING_DB_HOST' => 'db_host',
        'SPARRING_DB_PORT' => 'db_port',
        'SPARRING_DB_NAME' => 'db_name',
        'SPARRING_DB_USER' => 'db_user',
        'SPARRING_DB_PASSWORD' => 'db_pass',
    ];

    $databaseConfig = [];
    foreach ($mapping as $envKey => $configKey) {
        if (isset($env[$envKey]) && $env[$envKey] !== '') {
            $databaseConfig[$configKey] = $configKey === 'db_port' ? (int) $env[$envKey] : $env[$envKey];
        }
    }

    return $databaseConfig;
}

$config = array_replace($config, sparring_env_database_config());

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function base_path(): string
{
    $base = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    return $base === '/' ? '' : rtrim($base, '/');
}

function url_for(string $path = ''): string
{
    return base_path() . '/' . ltrim($path, '/');
}

function asset_url(string $path): string
{
    return url_for('static/' . ltrim($path, '/'));
}

function main_site_url(): string
{
    return 'https://iebi.rg.telkomuniversity.ac.id/';
}

function current_path(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $base = base_path();
    if ($base !== '' && strpos($path, $base) === 0) {
        $path = substr($path, strlen($base));
    }
    $path = '/' . trim($path, '/');
    return $path === '/' ? '/' : rtrim($path, '/');
}

function db(): PDO
{
    static $pdo = null;
    global $config;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    foreach (['db_host', 'db_name', 'db_user'] as $key) {
        if (!isset($config[$key])) {
            throw new RuntimeException('Database config is incomplete.');
        }
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $config['db_host'],
        $config['db_port'] ?? 3306,
        $config['db_name']
    );

    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}

function fetch_departments(): array
{
    return db()->query(
        'SELECT d.id, d.name_dept, COUNT(l.id) AS count
         FROM departments d
         LEFT JOIN lecturers l ON l.departments_id = d.id
         GROUP BY d.id, d.name_dept
         ORDER BY d.name_dept'
    )->fetchAll();
}

function fetch_lecturers(?string $department = null): array
{
    $sql = 'SELECT l.code_lec, l.name, l.expertise, l.nip, l.nidn, l.email, d.name_dept
            FROM lecturers l
            LEFT JOIN departments d ON l.departments_id = d.id';
    $params = [];

    if ($department !== null && $department !== '') {
        $sql .= ' WHERE d.name_dept = :department OR d.id = :department_id';
        $params['department'] = $department;
        $params['department_id'] = ctype_digit($department) ? (int) $department : -1;
    }

    $sql .= ' ORDER BY l.name';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['expertise'] = lecturer_expertise((string) ($row['code_lec'] ?? ''), $row['expertise'] ?? null);
    }
    unset($row);
    return $rows;
}

function clean_optional_text($value): string
{
    if ($value === null) {
        return '';
    }
    $text = trim((string) $value);
    return in_array(strtolower($text), ['', '-', 'none', 'nan', 'null'], true) ? '' : $text;
}

function keyword_expertise(string $code, int $limit = 8): string
{
    static $cache = [];
    $code = strtoupper(trim($code));
    if ($code === '') {
        return 'No expertise information available';
    }
    if (isset($cache[$code])) {
        return $cache[$code];
    }

    $stmt = db()->prepare(
        'SELECT keyword
         FROM lecturer_keywords
         WHERE code_lec = :code
         ORDER BY freq DESC, keyword ASC
         LIMIT ' . (int) $limit
    );
    $stmt->execute(['code' => $code]);

    $keywords = [];
    $seen = [];
    foreach ($stmt->fetchAll() as $row) {
        $keyword = str_replace('_', ' ', clean_optional_text($row['keyword'] ?? ''));
        if ($keyword === '') {
            continue;
        }
        $normalized = strtolower($keyword);
        if (isset($seen[$normalized])) {
            continue;
        }
        $seen[$normalized] = true;
        $keywords[] = $keyword;
    }

    $cache[$code] = $keywords ? implode(', ', $keywords) : 'No expertise information available';
    return $cache[$code];
}

function lecturer_expertise(string $code, $rawExpertise = null): string
{
    $expertise = clean_optional_text($rawExpertise);
    return $expertise !== '' ? $expertise : keyword_expertise($code);
}

function token_len(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function tokens_from_keyword(string $keyword): array
{
    $clean = strtolower(preg_replace('/[^\p{L}\p{N}\s,\.&-]+/u', ' ', $keyword) ?? '');
    $parts = preg_split('/[\s,\.&-]+/', $clean, -1, PREG_SPLIT_NO_EMPTY);
    $tokens = array_values(array_unique(array_filter($parts ?: [], fn($token) => token_len($token) > 1)));
    return array_slice($tokens, 0, 12);
}

function publication_match_parts(string $lecturerName): array
{
    $name = preg_replace('/,\s*.*/', '', $lecturerName) ?? '';
    $name = preg_replace('/^(dr|drg|prof|ir)\.?\s+/i', '', $name) ?? '';
    $tokens = array_values(array_filter(
        preg_split('/[^A-Za-z]+/', $name) ?: [],
        fn($token) => token_len($token) > 2
    ));

    $conditions = [];
    $params = [];
    foreach (array_slice($tokens, 0, 4) as $idx => $token) {
        $key = "token_$idx";
        $conditions[] = "UPPER(author) LIKE UPPER(:$key)";
        $params[$key] = '%' . $token . '%';
    }

    return [$conditions, $params];
}

function recent_publications(string $lecturerName, int $limit = 10): array
{
    [$conditions, $params] = publication_match_parts($lecturerName);
    if (!$conditions) {
        return [];
    }

    $sql = 'SELECT title, MAX(linkURL) AS linkURL
            FROM publication
            WHERE ' . implode(' AND ', $conditions) . '
            GROUP BY title
            LIMIT ' . (int) $limit;
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function publication_count(string $lecturerName): int
{
    [$conditions, $params] = publication_match_parts($lecturerName);
    if (!$conditions) {
        return 0;
    }

    $sql = 'SELECT COUNT(DISTINCT title)
            FROM publication
            WHERE ' . implode(' AND ', $conditions);
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

function lda_topics(string $code, string $language = 'id', int $limit = 0): array
{
    $dir = $language === 'en' ? 'LDAVis_en' : 'LDAVis_id';
    $file = __DIR__ . "/static/$dir/" . strtoupper($code) . '_topics.json';
    if (!is_file($file)) {
        return [];
    }

    $items = json_decode((string) file_get_contents($file), true);
    if (!is_array($items)) {
        return [];
    }

    return $limit > 0 ? array_slice($items, 0, $limit) : $items;
}

function lda_vis_path(string $code, string $language = 'id'): ?string
{
    $dir = $language === 'en' ? 'LDAVis_en' : 'LDAVis_id';
    $fileName = strtoupper($code) . '.html';
    $file = __DIR__ . "/static/$dir/" . $fileName;

    return is_file($file) ? asset_url($dir . '/' . $fileName) : null;
}

function recommendations(string $keyword, int $limit = 50): array
{
    $tokens = tokens_from_keyword($keyword);
    if (!$tokens) {
        return ['message' => 'Masukkan kata kunci yang valid', 'results' => []];
    }

    $where = [];
    $params = [];
    foreach ($tokens as $idx => $token) {
        $key = "kw_$idx";
        $where[] = "LOWER(lk.keyword) = :$key";
        $params[$key] = $token;
    }

    $sql = 'SELECT
                lk.code_lec,
                l.name,
                d.name_dept AS department,
                l.expertise,
                SUM(lk.freq) AS total_freq,
                COUNT(DISTINCT LOWER(lk.keyword)) AS matched_count,
                GROUP_CONCAT(DISTINCT lk.keyword ORDER BY lk.keyword SEPARATOR ", ") AS matched_keywords
            FROM lecturer_keywords lk
            JOIN lecturers l ON l.code_lec = lk.code_lec
            LEFT JOIN departments d ON d.id = l.departments_id
            WHERE ' . implode(' OR ', $where) . '
            GROUP BY lk.code_lec, l.name, d.name_dept, l.expertise
            ORDER BY matched_count DESC, total_freq DESC, l.name ASC
            LIMIT ' . (int) $limit;
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    if (!$rows) {
        $where = [];
        $params = [];
        foreach ($tokens as $idx => $token) {
            $key = "like_$idx";
            $where[] = "LOWER(lk.keyword) LIKE :$key";
            $params[$key] = '%' . $token . '%';
        }
        $sql = str_replace('WHERE ' . implode(' OR ', array_map(fn($i) => "LOWER(lk.keyword) = :kw_$i", array_keys($tokens))), 'WHERE ' . implode(' OR ', $where), $sql);
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    }

    $maxFreq = max(array_map(fn($row) => (int) $row['total_freq'], $rows ?: [['total_freq' => 1]]));
    $results = [];

    foreach ($rows as $row) {
        $score = $maxFreq > 0 ? ((int) $row['total_freq'] / $maxFreq) : 0;
        $results[] = [
            'lecture' => $row['name'],
            'code_lec' => $row['code_lec'],
            'score' => round($score, 4),
            'department' => $row['department'] ?: '-',
            'expertise' => lecturer_expertise((string) $row['code_lec'], $row['expertise'] ?? null),
            'matched_keywords' => array_values(array_filter(array_map('trim', explode(',', (string) $row['matched_keywords'])))),
            'publications' => array_slice(recent_publications((string) $row['name']), 0, 3),
            'topics' => lda_topics((string) $row['code_lec'], 'id', 3),
        ];
    }

    return [
        'message' => 'Rekomendasi berbasis kecocokan keyword untuk: ' . implode(' ', $tokens),
        'results' => $results,
    ];
}

function lecturer_by_identifier(string $identifier): ?array
{
    $stmt = db()->prepare(
        'SELECT l.*, d.name_dept
         FROM lecturers l
         LEFT JOIN departments d ON l.departments_id = d.id
         WHERE l.code_lec = :identifier OR LOWER(l.name) = LOWER(:identifier)
         LIMIT 1'
    );
    $stmt->execute(['identifier' => $identifier]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $row['expertise'] = lecturer_expertise((string) ($row['code_lec'] ?? ''), $row['expertise'] ?? null);
    return $row;
}

function render_layout(string $title, string $content, string $mode = 'default'): void
{
    $homeMode = $mode === 'home';
    $navClass = $homeMode ? 'navbar-dark bg-transparent position-absolute w-100 z-3' : 'fixed-top';
    $navStyle = $homeMode ? ' style="top:0;left:0;"' : '';
    $buttonClass = $homeMode ? 'btn-transparent' : '';
    $buttonStyle = $homeMode ? '' : 'background-color:#1c4c54;border-color:#1c4c54;';

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>' . e($title) . '</title>';
    echo '<link rel="icon" type="image/x-icon" href="' . e(asset_url('assets/favicon.ico')) . '">';
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.5.0/font/bootstrap-icons.css" rel="stylesheet">';
    echo '<link href="https://fonts.googleapis.com/css?family=Lato:300,400,700,300italic,400italic,700italic" rel="stylesheet">';
    echo '<link href="' . e(asset_url('css/styles.css')) . '" rel="stylesheet">';
    echo '<link href="' . e(asset_url('css/lecturer.css')) . '" rel="stylesheet">';
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/css/bootstrap.min.css" rel="stylesheet">';

    if (!$homeMode) {
        echo '<style>
            body.sparring-inner {
                background: #f8fafc !important;
                color: #1e293b !important;
            }
            body.sparring-inner .navbar.fixed-top {
                background: #1c555b !important;
                box-shadow: none !important;
                min-height: 86px;
            }
            body.sparring-inner .hero-search {
                background: #ffffff !important;
                color: #1e293b !important;
                padding-top: 7rem !important;
                padding-bottom: 2rem !important;
                border-bottom: 1px solid #e2e8f0;
                margin-bottom: 2rem;
            }
            body.sparring-inner .card,
            body.sparring-inner .filter-card,
            body.sparring-inner .card-siredo,
            body.sparring-inner .profile-summary-card,
            body.sparring-inner .profile-lda-card,
            body.sparring-inner .profile-publication-card {
                background: #ffffff !important;
                color: #1e293b !important;
                border-color: #e2e8f0 !important;
            }
            body.sparring-inner .filter-card {
                border-radius: 0.75rem;
                padding: 1.5rem;
                margin-bottom: 2rem;
                box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08) !important;
            }
            body.sparring-inner .section-label {
                color: #64748b !important;
                display: flex;
                font-size: 0.7rem;
                font-weight: 700;
                letter-spacing: 0.5px;
                margin-bottom: 0.5rem;
                text-transform: uppercase;
            }
            body.sparring-inner .results-stats {
                align-items: center;
                display: flex;
                flex-wrap: wrap;
                gap: 1rem;
                justify-content: space-between;
                margin: 2rem 0 1.5rem;
            }
            body.sparring-inner .card-siredo {
                border-radius: 1rem;
                position: relative;
                transition: transform 0.2s ease, box-shadow 0.2s ease;
            }
            body.sparring-inner .card-siredo:hover {
                box-shadow: 0 18px 40px rgba(15, 23, 42, 0.12) !important;
                transform: translateY(-3px);
            }
            body.sparring-inner .photo-wrapper {
                flex: 0 0 70px;
                height: 70px;
                width: 70px;
            }
            body.sparring-inner .photo-wrapper img {
                border-radius: 0.75rem !important;
                height: 70px !important;
                object-fit: cover;
                width: 70px !important;
            }
            body.sparring-inner .top-match-badge {
                background: #10b981;
                border-radius: 0.4rem;
                color: #ffffff;
                font-size: 0.65rem;
                font-weight: 700;
                padding: 0.35rem 0.8rem;
                position: absolute;
                right: 1rem;
                text-transform: uppercase;
                top: 1rem;
            }
            body.sparring-inner .badge-topic {
                background: transparent;
                border: 1px solid #cbd5e1;
                border-radius: 0.5rem;
                color: #64748b;
                font-size: 0.7rem;
                font-weight: 600;
                padding: 0.4rem 0.7rem;
            }
            body.sparring-inner .separator {
                background: #e2e8f0;
                height: 1px;
                margin: 1.5rem 0;
                width: 100%;
            }
            body.sparring-inner .score-large {
                color: #dc3545;
                font-size: 2.2rem;
                font-weight: 800;
                line-height: 1;
            }
            body.sparring-inner .btn-view-profile {
                background: #f1f5f9;
                border: 0;
                border-radius: 0.5rem;
                color: #1e293b;
                font-size: 0.85rem;
                font-weight: 700;
                padding: 0.75rem;
            }
            body.sparring-inner .btn-view-profile:hover {
                background: #e2e8f0;
                color: #0f172a;
            }
            body.sparring-inner .card-header,
            body.sparring-inner .card-body,
            body.sparring-inner .publication-row {
                background: #ffffff !important;
                color: #1e293b !important;
            }
            body.sparring-inner .text-dark,
            body.sparring-inner .card-title,
            body.sparring-inner .publication-title,
            body.sparring-inner h1,
            body.sparring-inner h2,
            body.sparring-inner h3,
            body.sparring-inner h4,
            body.sparring-inner h5,
            body.sparring-inner p {
                color: #1e293b !important;
            }
            body.sparring-inner .text-muted,
            body.sparring-inner .text-secondary {
                color: #64748b !important;
            }
            body.sparring-inner .form-control,
            body.sparring-inner .form-select,
            body.sparring-inner .search-pill,
            body.sparring-inner .lecturer-filter-control {
                background-color: #ffffff !important;
                color: #1e293b !important;
                border-color: #dbe4ee !important;
            }
            body.sparring-inner .badge-engine {
                background: #0f172a !important;
                color: #ffffff !important;
            }
            body.sparring-inner #approachPanel {
                background: #212529 !important;
                color: #ffffff !important;
                border-color: #343a40 !important;
            }
            body.sparring-inner #approachPanel .card-body,
            body.sparring-inner #approachPanel label {
                background: transparent !important;
                color: #ffffff !important;
            }
            body.sparring-inner #approachPanel .form-select {
                background-color: #212529 !important;
                color: #ffffff !important;
                border-color: #3b82f6 !important;
            }
        </style>';
    }
    echo '</head><body class="' . e($homeMode ? 'sparring-home' : 'sparring-inner') . '">';
    echo '<nav class="navbar ' . e($navClass) . '"' . $navStyle . ' data-bs-theme="dark"><div class="container-fluid">';
    echo '<a class="navbar-brand d-flex align-items-center gap-2" href="' . e(main_site_url()) . '">';
    echo '<img src="' . e(asset_url('assets/img/logonew.png')) . '" alt="Telkom University logo" class="navbar-logo">';
    echo '<span class="visually-hidden">SPARRING</span></a>';
    echo '<div class="navbar-nav ms-auto d-flex flex-row align-items-center gap-3" style="position:relative;">';
    echo '<a class="nav-link text-white p-0 m-0" href="' . e(url_for('/')) . '">Home</a>';
    echo '<a class="nav-link text-white p-0 m-0" href="' . e(url_for('/lecturers')) . '">Lecturers</a>';
    echo '<button id="approachToggle" type="button" class="btn ' . e($buttonClass) . ' btn-sm d-flex align-items-center justify-content-center m-0" title="Pengaturan Pendekatan" aria-label="Pengaturan Pendekatan" aria-expanded="false" style="width:36px;height:36px;border-radius:50%;' . e($buttonStyle) . 'color:#ffffff;align-self:center;">';
    echo '<i class="bi-gear" style="pointer-events:none;color:#ffffff;font-size:1rem;line-height:inherit;vertical-align:middle;display:inline-block;align-self:center;"></i></button>';
    echo '<div id="approachPanel" class="card shadow-sm" style="position:absolute;right:0;top:48px;width:260px;display:none;z-index:1060;"><div class="card-body py-2">';
    echo '<label for="approachSelect" class="form-label mb-1">Approach</label><select class="form-select form-select-sm" id="approachSelect"><option value="hybrid" selected>Hybrid (Naive Bayes)</option>
                            <option value="onevsrest">One-vs-Rest</option></select>';
    echo '</div></div></div></div></nav>';
    echo '<div class="container-fluid my-0 px-0">' . $content . '</div>';
    echo '<script>document.addEventListener("DOMContentLoaded",function(){const t=document.getElementById("approachToggle"),p=document.getElementById("approachPanel");if(t&&p){function v(s){p.style.display=s?"block":"none";t.setAttribute("aria-expanded",s?"true":"false")}t.addEventListener("click",function(e){e.preventDefault();e.stopPropagation();v(p.style.display==="none")});t.addEventListener("keydown",function(e){if(e.key==="Enter"||e.key===" "){e.preventDefault();e.stopPropagation();v(p.style.display==="none")}});document.addEventListener("click",function(e){if(!t.contains(e.target)&&!p.contains(e.target))v(false)})}const a=document.getElementById("approachSelect");if(a){const params=new URLSearchParams(window.location.search);const urlApproach=params.get("approach");if(urlApproach){a.value=urlApproach}a.addEventListener("change",function(e){document.querySelectorAll("input[name=approach]").forEach(function(el){el.value=e.target.value})})}});</script>';
    echo '<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>';
    echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/js/bootstrap.bundle.min.js"></script>';
    echo '</body></html>';
}

function home_page(): void
{
    ob_start();
    ?>
    <style>
        body.sparring-home {
            overflow-x: hidden;
        }
        header.masthead {
            background-image: none !important;
            min-height: 100vh;
            display: flex;
            align-items: center;
            isolation: isolate;
            overflow: hidden;
        }
        header.masthead::before {
            animation: sparringHeroDrift 16s ease-in-out infinite;
            background: url("<?= e(asset_url('assets/img/bg.masthead.webp')) ?>") center center / cover no-repeat;
            content: "";
            inset: -4%;
            opacity: 1;
            position: absolute;
            transform: translate3d(-2.5%, 0, 0);
            width: auto;
            height: auto;
            z-index: 0;
        }
        header.masthead::after {
            background: rgba(28, 55, 94, 0.5);
            content: "";
            inset: 0;
            pointer-events: none;
            position: absolute;
            z-index: 1;
        }
        header.masthead .container {
            z-index: 2;
        }
        .hero-text {
            text-shadow: 0px 2px 10px rgba(0, 0, 0, 0.7);
        }
        .masthead-search {
            margin-top: 8rem !important;
        }
        @keyframes sparringHeroDrift {
            0% {
                transform: translate3d(-2.5%, 0, 0);
            }
            50% {
                transform: translate3d(2.5%, 0, 0);
            }
            100% {
                transform: translate3d(-2.5%, 0, 0);
            }
        }
        @media (prefers-reduced-motion: reduce) {
            header.masthead::before {
                animation: none;
                transform: translate3d(0, 0, 0);
            }
        }
        @media (max-width: 767.98px) {
            header.masthead {
                padding-top: 8rem !important;
                padding-bottom: 5rem !important;
            }
        }
    </style>
    <header class="masthead">
        <div class="container position-relative">
            <div class="row justify-content-center">
                <div class="col-xl-10">
                    <div class="text-center text-white hero-text">
                        <h2 class="mb-1 fw-bold">Welcome to SPARRING</h2>
                        <h5 class="mb-4">Sistem Partner Research Matching</h5>
                        <form class="form-search masthead-search" action="<?= e(url_for('/search')) ?>" method="GET">
                            <div class="row">
                                <div class="col">
                                    <input class="form-control form-control-lg" name="keyword" id="text" type="text" placeholder="type here.. example: Papua, Pendidikan, Autis, IoT" aria-label="Sparring" required>
                                    <input type="hidden" name="approach" id="hiddenApproachInput" value="hybrid">
                                </div>
                                <div class="col-auto mt-3 mt-md-0">
                                    <button class="btn btn-primary btn-lg" id="submitButton" type="submit">Search</button>
                                </div>
                            </div>
                            <div class="advancesearch"><p></p></div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </header>



    <?php
    render_layout('Welcome to Sparring', ob_get_clean(), 'home');
}

function search_page(): void
{
    $keyword = trim((string) ($_GET['keyword'] ?? ''));
    $approach = trim((string) ($_GET['approach'] ?? 'hybrid')) ?: 'hybrid';
    $departments = fetch_departments();

    ob_start();
    ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <div class="hero-search">
        <div class="container text-center">
            <h2 class="mb-3">Recommendation Results</h2>
            <p class="text-muted">Showing research partners matching your keywords</p>
            <div class="row mt-4">
                <div class="col-lg-8 mx-auto">
                    <form id="searchForm">
                        <div class="p-1 px-2 bg-light rounded-pill border d-flex align-items-center gap-2">
                            <span class="ms-3 text-muted"><i class="bi bi-search"></i></span>
                            <input type="text" id="keywordInput" class="form-control border-0 bg-transparent flex-grow-1 shadow-none py-3" name="keyword" value="<?= e($keyword) ?>" placeholder="Enter research area...">
                            <button type="submit" class="btn text-white rounded-pill px-4 fw-bold" style="background-color:#1c4c54;border-color:#1c4c54;">Search</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <div class="container pb-5">
        <div class="filter-card shadow-sm">
            <div class="row g-4">
                <div class="col-md-4"><div class="section-label">Department</div><select id="deptFilter" class="form-select form-select-siredo"><option value="">All Departments</option><?php foreach ($departments as $dept): ?><option value="<?= e($dept['name_dept']) ?>"><?= e($dept['name_dept']) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-4"><div class="section-label">Minimum Score</div><select id="scoreFilter" class="form-select form-select-siredo"><option value="0">All Scores</option><option value="0.25">25% or Higher</option><option value="0.50">50% or Higher</option><option value="0.75">75% or Higher</option></select></div>
                <div class="col-md-4"><div class="section-label">Sort By</div><select id="sortOption" class="form-select form-select-siredo"><option value="score_desc">Highest Compatibility (Default)</option><option value="score_asc">Lowest Compatibility</option><option value="name_asc">Name (A-Z)</option></select></div>
            </div>
        </div>
        <div class="results-stats"><div id="resultsOverviewRow" style="display:none;align-items:center;gap:.75rem;"><p class="mb-0 fw-700 text-dark" style="font-size:1.1rem;letter-spacing:-.5px;" id="resultsCountLabel">Searching...</p><span id="filteredCountBadge" class="badge rounded-pill px-3" style="display:none;font-size:.75rem;font-weight:700;background-color:rgba(28,76,84,.1);color:#1c4c54;"></span></div><div class="d-flex align-items-center"><span class="badge-engine">AI ENGINE: <?= e(strtoupper($approach ?: 'hybrid')) ?></span></div></div>
        <div id="statusMessage" class="text-center py-5"><div class="spinner-grow" style="width:3rem;height:3rem;color:#1c4c54;" role="status"></div><p class="mt-4 text-muted fw-600">Ranking expertise profiles...</p></div>
        <div class="row g-4" id="cardContainer" style="display:none;"></div>
        <div class="text-center mt-5" id="paginationContainer" style="display:none;"><button id="showMoreBtn" class="btn px-5 py-3 rounded-pill fw-800" style="border-width:2px;color:#1c4c54;border-color:#1c4c54;background:transparent;">Load More Recommendations</button></div>
    </div>
    <div id="jsData" data-keyword="<?= e($keyword) ?>" data-approach="<?= e($approach) ?>" style="display:none;"></div>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const CARDS_PER_PAGE = 8;
        let allResults = [], filteredResults = [], currentPage = 1;
        const dataContainer = document.getElementById('jsData');
        const initialKeyword = dataContainer.dataset.keyword;
        const initialApproach = dataContainer.dataset.approach || 'hybrid';
        const personImageUrl = <?= json_encode(asset_url('assets/img/person.png')) ?>;
        const recommendationApiUrl = <?= json_encode(url_for('/rekomendasi')) ?>;
        const searchPageUrl = <?= json_encode(url_for('/search')) ?>;
        const profileBaseUrl = <?= json_encode(url_for('/profile/__IDENTIFIER__')) ?>;
        const cardContainer = document.getElementById('cardContainer');
        const statusMessage = document.getElementById('statusMessage');
        function esc(v){return String(v ?? '').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));}
        function createCard(result, rank) {
            const { lecture, score, department, expertise, code_lec, topics } = result;
            const col = document.createElement('div'); col.className = 'col-lg-6 col-md-12 mb-4';
            const displayScore = (Number(score || 0) * 100).toFixed(0);
            const nameParts = String(lecture || '').split(',');
            const mainName = nameParts[0].trim();
            const degrees = nameParts.length > 1 ? nameParts.slice(1).join(',').trim() : '';
            let topicsHtml = '';
            if (topics && topics.length > 0) {
                const badges = topics.slice(0,3).map(t => `<span class="badge-topic">#${esc(t)}</span>`).join('');
                topicsHtml = `<div class="mb-4"><div class="section-label">Topics Focus</div><div class="d-flex flex-wrap gap-2">${badges}</div></div>`;
            }
            const profileUrl = profileBaseUrl.replace('__IDENTIFIER__', encodeURIComponent(code_lec || lecture || ''));
            col.innerHTML = `<div class="card card-siredo h-100 p-4">${rank === 1 ? '<div class="top-match-badge">Ranked #1</div>' : ''}<div class="d-flex align-items-center gap-3 mb-4"><div class="photo-wrapper"><img src="${personImageUrl}" alt="${esc(lecture)}"></div><div class="overflow-hidden"><h5 class="fw-700 mb-0 text-dark text-truncate" style="font-size:1.15rem;">${esc(mainName)}</h5>${degrees ? `<p class="text-muted small mb-1 text-truncate">${esc(degrees)}</p>` : ''}<div class="text-secondary small fw-600"><i class="bi bi-mortarboard me-1"></i>${esc(department || '-')}</div></div></div><div class="mb-4"><div class="section-label">Expertise Reference</div><p class="small text-secondary lh-base mb-0 fw-500" style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">${esc(expertise || '-')}</p></div>${topicsHtml}<div class="separator"></div><div class="mt-auto"><div class="row align-items-center"><div class="col-6"><div class="section-label mb-1">Match Score</div><div class="score-large">${displayScore}<span style="font-size:1rem;opacity:.5;">%</span></div></div><div class="col-6"><a href="${profileUrl}" class="btn btn-view-profile w-100 d-flex align-items-center justify-content-center gap-2">View Profile <i class="bi bi-chevron-right"></i></a></div></div></div></div>`;
            return col;
        }
        function render(){cardContainer.innerHTML='';const items=filteredResults.slice(0,currentPage*CARDS_PER_PAGE);if(items.length===0){cardContainer.innerHTML='<div class="col-12 text-center py-5 mt-4"><div class="icon-circle"><i class="bi bi-search" style="font-size:3.5rem;"></i></div><h2 class="fw-800 text-dark mb-3">Match Not Found</h2><p class="text-muted">Try broadening your search or resetting the filters.</p></div>';}else{items.forEach((r,i)=>cardContainer.appendChild(createCard(r,i+1)));}cardContainer.style.display='flex';document.getElementById('paginationContainer').style.display=(filteredResults.length>items.length)?'block':'none';}
        function process(){const d=document.getElementById('deptFilter').value;const s=parseFloat(document.getElementById('scoreFilter').value);const sort=document.getElementById('sortOption').value;filteredResults=allResults.filter(r=>(d===''||r.department===d)&&Number(r.score)>=s);if(sort==='score_desc')filteredResults.sort((a,b)=>b.score-a.score);else if(sort==='score_asc')filteredResults.sort((a,b)=>a.score-b.score);else filteredResults.sort((a,b)=>String(a.lecture).localeCompare(String(b.lecture)));currentPage=1;render();const badge=document.getElementById('filteredCountBadge');if(filteredResults.length!==allResults.length){badge.style.display='inline-block';badge.textContent=`${filteredResults.length} Results Found`;}else badge.style.display='none';}
        async function fetchData(){if(!initialKeyword){statusMessage.innerHTML='<p class="text-muted">Enter a keyword to start matching.</p>';return;}try{const r=await fetch(recommendationApiUrl,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({kata_kunci:initialKeyword,approach:initialApproach})});const d=await r.json();if(d.results&&d.results.length>0){statusMessage.style.display='none';allResults=d.results;document.getElementById('resultsOverviewRow').style.display='flex';document.getElementById('resultsCountLabel').innerHTML=`Found <span style="color:#dc3545">${allResults.length}</span> Matching Partners`;process();}else{statusMessage.innerHTML=`<div class="py-5 text-center"><div class="icon-circle"><i class="bi bi-search" style="font-size:3.5rem;"></i></div><h2 class="fw-800 text-dark mb-3">Match Not Found</h2><p class="text-muted">Sorry, we couldn't find any researchers with expertise in "<strong>${esc(initialKeyword)}</strong>".</p></div>`;}}catch(e){statusMessage.innerHTML=`<div class="alert alert-danger">Error: ${esc(e.message)}</div>`;}}
        ['deptFilter','scoreFilter','sortOption'].forEach(id=>document.getElementById(id).addEventListener('change',process));
        document.getElementById('showMoreBtn').addEventListener('click',()=>{currentPage++;render();});
        document.getElementById('searchForm').addEventListener('submit',e=>{e.preventDefault();const kw=document.getElementById('keywordInput').value.trim();const currentApproach=document.getElementById('approachSelect')?.value||initialApproach||'hybrid';window.location.href=`${searchPageUrl}?keyword=${encodeURIComponent(kw)}&approach=${encodeURIComponent(currentApproach)}`;});
        fetchData();
    });
    </script>
    <?php
    render_layout('Recommendation Results - SPARRING', ob_get_clean());
}

function lecturers_page(): void
{
    $selected = trim((string) ($_GET['department'] ?? ''));
    $departments = fetch_departments();
    $lecturers = fetch_lecturers();

    ob_start();
    ?>
    <style>.lecturer-filter-control,.lecturer-filter-control::placeholder,.lecturer-filter-control option{font-family:"Times New Roman",Times,serif;}</style>
    <div class="container py-5">
        <div class="row mb-4"><div class="col-12"><h2 class="text-center mb-3">Lecturer Profiles</h2><p class="text-center text-muted">Browse and filter lecturers by department</p></div></div>
        <div class="row mb-4"><div class="col-lg-10 mx-auto"><div class="card shadow-sm"><div class="card-body"><div class="row g-3 align-items-center"><div class="col-md-7"><input type="text" class="form-control lecturer-filter-control" id="searchInput" placeholder="Search by lecturer name or code..."></div><div class="col-md-5"><select class="form-select lecturer-filter-control" id="departmentFilter"><option value="">All Departments</option><?php foreach ($departments as $dept): ?><option value="<?= e($dept['name_dept']) ?>" <?= $dept['name_dept'] === $selected ? 'selected' : '' ?>><?= e($dept['name_dept']) ?> (<?= e($dept['count'] ?? 0) ?>)</option><?php endforeach; ?></select></div></div></div></div></div></div>
        <div class="row" id="lecturersGrid">
            <?php foreach ($lecturers as $lecturer): ?>
                <div class="col-lg-4 col-md-6 col-sm-12 mb-4 lecturer-card" data-department="<?= e($lecturer['name_dept'] ?: 'Unknown') ?>" data-name="<?= e(strtolower((string) $lecturer['name'])) ?>" data-code="<?= e(strtolower((string) $lecturer['code_lec'])) ?>">
                    <div class="card mb-2 h-100 shadow-sm lecturer-card-inner"><div class="card-body d-flex align-items-stretch"><div class="left-col text-center me-3 d-flex flex-column align-items-center"><img src="<?= e(asset_url('assets/img/person.png')) ?>" alt="Lecturer" class="rounded-circle lecturer-avatar"><a href="<?= e(url_for('/profile/' . rawurlencode((string) $lecturer['code_lec']))) ?>" class="btn btn-outline-primary mt-3" style="width:95px">View Profile</a></div><div class="right-col d-flex flex-column"><h5 class="card-title lecturer-name mb-2"><?= e($lecturer['name']) ?></h5><hr><p class="card-text lecturer-dept mb-3"><strong>Department</strong><br><?= e($lecturer['name_dept'] ?: 'Unknown Department') ?></p><hr><div class="lecturer-info"><p class="card-text mb-1 small expertise-list"><strong>Expertise:</strong><br><?= e($lecturer['expertise'] ?: 'No expertise information available') ?></p></div></div></div></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <script>document.addEventListener('DOMContentLoaded',function(){const s=document.getElementById('searchInput'),d=document.getElementById('departmentFilter'),cards=document.querySelectorAll('.lecturer-card');function f(){const q=s.value.toLowerCase(),dep=d.value;cards.forEach(c=>{const ok=(c.dataset.name.includes(q)||c.dataset.code.includes(q))&&(!dep||c.dataset.department===dep);c.style.display=ok?'block':'none';});}s.addEventListener('input',f);d.addEventListener('change',f);f();});</script>
    <?php
    render_layout('Lecturers - SPARRING', ob_get_clean());
}

function profile_page(string $identifier): void
{
    $lecturer = lecturer_by_identifier(rawurldecode($identifier));
    if (!$lecturer) {
        http_response_code(404);
        render_layout('Lecturer Not Found', '<div class="container py-5"><h1>Lecturer not found</h1></div>');
        return;
    }
    $publications = recent_publications((string) $lecturer['name'], 10);
    $publicationCount = publication_count((string) $lecturer['name']);
    if ($publicationCount === 0 && $publications) {
        $publicationCount = count($publications);
    }
    $topicsId = lda_topics((string) $lecturer['code_lec'], 'id', 8);
    $topicsEn = lda_topics((string) $lecturer['code_lec'], 'en', 8);
    $ldaIdPath = lda_vis_path((string) $lecturer['code_lec'], 'id');
    $ldaEnPath = lda_vis_path((string) $lecturer['code_lec'], 'en');
    $defaultLdaPath = $ldaIdPath ?: $ldaEnPath;

    ob_start();
    ?>
    <style>
        .profile-page {
            padding-top: 6rem;
            padding-bottom: 4rem;
        }
        .profile-summary-card,
        .profile-lda-card,
        .profile-publication-card {
            border: 1px solid #dee2e6;
            border-radius: 0.35rem;
            overflow: hidden;
        }
        .profile-lda-frame {
            width: 100%;
            min-height: 620px;
            border: 0;
            background: #fff;
        }
        .publication-row {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            padding: 1rem 1.25rem;
            border-top: 1px solid #e9ecef;
        }
        .publication-row:first-of-type {
            border-top: 0;
        }
        .publication-title {
            font-weight: 700;
            color: #111827;
            margin-bottom: 0.35rem;
        }
        .profile-topic-badge {
            border: 1px solid #cbd5e1;
            color: #475569;
            background: #f8fafc;
            border-radius: 999px;
            display: inline-flex;
            padding: 0.35rem 0.7rem;
            font-size: 0.8rem;
            font-weight: 600;
            margin: 0 0.35rem 0.45rem 0;
        }
        @media (max-width: 768px) {
            .profile-lda-frame {
                min-height: 520px;
            }
            .publication-row {
                flex-direction: column;
            }
        }
    </style>
    <div class="container profile-page">
        <div class="card shadow-sm profile-summary-card">
            <div class="card-body">
                <div class="row">
                    <div class="col-lg-4 text-center mb-4 mb-lg-0 border-end">
                        <img src="<?= e(asset_url('assets/img/person.png')) ?>" alt="avatar" class="rounded-circle img-fluid mb-3" style="width:150px;">
                        <h5 class="my-3"><?= e($lecturer['name']) ?></h5>
                        <p class="text-muted mb-1">Lecturer</p>
                        <p class="text-muted mb-2"><?= e($lecturer['name_dept'] ?: 'Department Not Set') ?></p>
                    </div>
                    <div class="col-lg-8">
                        <h4 class="mb-3">Profile</h4>
                        <p><strong>Code:</strong> <?= e($lecturer['code_lec']) ?></p>
                        <p><strong>NIP:</strong> <?= e($lecturer['nip'] ?? '-') ?></p>
                        <p><strong>NIDN:</strong> <?= e($lecturer['nidn'] ?? '-') ?></p>
                        <p><strong>Email:</strong> <?= e($lecturer['email'] ?? '-') ?></p>
                        <p><strong>Expertise:</strong><br><?= e($lecturer['expertise'] ?? '-') ?></p>
                        <?php foreach (array_merge($topicsId, $topicsEn) as $topic): ?>
                            <span class="profile-topic-badge"><?= e($topic) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($defaultLdaPath): ?>
            <div class="card shadow-sm profile-lda-card mt-4">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h3 class="h4 mb-0">Topic Modeling Visualization</h3>
                    <?php if ($ldaIdPath && $ldaEnPath): ?>
                        <div class="btn-group btn-group-sm" role="group" aria-label="Topic model language">
                            <button type="button" class="btn btn-outline-secondary active" data-lda-src="<?= e($ldaIdPath) ?>">ID</button>
                            <button type="button" class="btn btn-outline-secondary" data-lda-src="<?= e($ldaEnPath) ?>">EN</button>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="card-body p-0">
                    <iframe id="ldaFrame" class="profile-lda-frame" src="<?= e($defaultLdaPath) ?>" title="Topic Modeling Visualization"></iframe>
                </div>
            </div>
        <?php endif; ?>

        <div class="card shadow-sm profile-publication-card mt-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h3 class="h4 mb-0">Publications</h3>
                <span class="badge rounded-pill bg-primary px-3 py-2"><?= e($publicationCount) ?> Publications</span>
            </div>
            <div class="card-body p-0">
                <?php if (!$publications): ?>
                    <div class="p-4 text-muted">No publication data available.</div>
                <?php else: ?>
                    <?php foreach ($publications as $pub): ?>
                        <div class="publication-row">
                            <div>
                                <div class="publication-title"><?= e($pub['title']) ?></div>
                                <div class="text-muted small">Year: -</div>
                            </div>
                            <?php if (!empty($pub['linkURL'])): ?>
                                <a class="btn btn-outline-primary btn-sm align-self-start" href="<?= e($pub['linkURL']) ?>" target="_blank" rel="noopener">View</a>
                            <?php else: ?>
                                <button class="btn btn-outline-secondary btn-sm align-self-start" type="button" disabled>View</button>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script>
        document.querySelectorAll('[data-lda-src]').forEach(function(button) {
            button.addEventListener('click', function() {
                document.querySelectorAll('[data-lda-src]').forEach(function(item) {
                    item.classList.remove('active');
                });
                button.classList.add('active');
                document.getElementById('ldaFrame').src = button.dataset.ldaSrc;
            });
        });
    </script>
    <?php
    render_layout((string) $lecturer['name'], ob_get_clean());
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = current_path();

try {
    if ($path === '/' && $method === 'GET') {
        home_page();
    } elseif ($path === '/search' && $method === 'GET') {
        search_page();
    } elseif ($path === '/lecturers' && $method === 'GET') {
        lecturers_page();
    } elseif ($path === '/rekomendasi' && $method === 'POST') {
        $payload = json_decode((string) file_get_contents('php://input'), true) ?: [];
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(recommendations((string) ($payload['kata_kunci'] ?? ''), 50), JSON_UNESCAPED_SLASHES);
    } elseif (preg_match('#^/profile/(.+)$#', $path, $match) && $method === 'GET') {
        profile_page($match[1]);
    } else {
        http_response_code(404);
        render_layout('Page Not Found', '<div class="container py-5"><h1>Page not found</h1><p><a href="' . e(url_for('/')) . '">Back to Home</a></p></div>');
    }
} catch (Throwable $error) {
    http_response_code(500);
    render_layout('Application Error', '<div class="container py-5"><div class="alert alert-danger"><h1>Application Error</h1><p>' . e($error->getMessage()) . '</p></div></div>');
}
