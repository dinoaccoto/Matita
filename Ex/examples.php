<?php
// ─────────────────────────────────────────────────────────────
//  Download handler — must run BEFORE any output
// ─────────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'download' && isset($_GET['file'])) {
    $requestedFile = basename($_GET['file']);
    $filePath      = __DIR__ . DIRECTORY_SEPARATOR . $requestedFile;

    if (
        pathinfo($requestedFile, PATHINFO_EXTENSION) === 'txt'
        && file_exists($filePath)
        && is_file($filePath)
    ) {
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $requestedFile . '"');
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: no-cache, no-store');
        readfile($filePath);
    } else {
        http_response_code(404);
        echo 'File not found.';
    }
    exit;
}

// ─────────────────────────────────────────────────────────────
//  Raw-content handler (AJAX copy)
// ─────────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'raw' && isset($_GET['file'])) {
    $requestedFile = basename($_GET['file']);
    $filePath      = __DIR__ . DIRECTORY_SEPARATOR . $requestedFile;

    if (
        pathinfo($requestedFile, PATHINFO_EXTENSION) === 'txt'
        && file_exists($filePath)
        && is_file($filePath)
    ) {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-cache, no-store');
        readfile($filePath);
    } else {
        http_response_code(404);
        echo 'File not found.';
    }
    exit;
}

// ─────────────────────────────────────────────────────────────
//  Metadata parser
// ─────────────────────────────────────────────────────────────
function parseMatitaHeader(string $filepath): array
{
    $meta = [
        'name'        => '',
        'category'    => [], // Modificato in array
        'version'     => '',
        'date'        => '',
        'author'      => '',
        'language'    => '',
        'matita_ver'  => '',
        'description' => '',
    ];

    $lines = file($filepath, FILE_IGNORE_NEW_LINES);
    if ($lines === false) return $meta;

    $blockStart = null;
    $blockEnd   = null;
    foreach ($lines as $i => $line) {
        $stripped = preg_replace('/^#+\s*/', '', $line);
        if (strpos($stripped, '*****') !== false) {
            if ($blockStart === null) {
                $blockStart = $i;
            } else {
                $blockEnd = $i;
                break;
            }
        }
    }

    if ($blockStart === null || $blockEnd === null) return $meta;

    $block = array_slice($lines, $blockStart + 1, $blockEnd - $blockStart - 1);

    $descLines = [];
    $inDesc    = false;
    $descKey   = 'description';

    foreach ($block as $rawLine) {
        $line = preg_replace('/^#+\s?/', '', $rawLine);

        if ($inDesc) {
            if (preg_match('/^([A-Za-z][A-Za-z\s]*):\s*(.*)/', $line, $m)) {
                $meta[$descKey] = implode(' ', $descLines);
                $descLines = [];
                $inDesc    = false;
            } else {
                $descLines[] = trim($line);
                continue;
            }
        }

        if (preg_match('/^([A-Za-z][A-Za-z\s]*):\s*(.*)/', $line, $m)) {
            $key   = strtolower(trim($m[1]));
            $value = trim($m[2]);

            switch ($key) {
                case 'name':        
                    $meta['name']       = $value; 
                    break;
                case 'category':    
                    // Estrae, pulisce e scarta eventuali categorie vuote generate da virgole finali
                    $cats = array_map('trim', explode(',', $value));
                    $meta['category']   = array_filter($cats, fn($c) => $c !== ''); 
                    break;
                case 'program ver': 
                    $meta['version']    = $value; 
                    break;
                case 'date':        
                    $meta['date']       = $value; 
                    break;
                case 'author':      
                    $meta['author']     = $value; 
                    break;
                case 'language':    
                    $meta['language']   = strtoupper($value); 
                    break; 
                case 'matita ver':  
                    $meta['matita_ver'] = $value; 
                    break;
                case 'description':
                    $inDesc    = true;
                    $descLines = $value !== '' ? [$value] : [];
                    break;
            }
        }
    }

    if ($inDesc && count($descLines) > 0) {
        $meta[$descKey] = implode(' ', $descLines);
    }

    return $meta;
}

// ─────────────────────────────────────────────────────────────
//  Collect & parse all .txt files
// ─────────────────────────────────────────────────────────────
$txtFiles = glob(__DIR__ . DIRECTORY_SEPARATOR . '*.txt');
$programs = [];
$uniqueCategories = [];
$uniqueLanguages = [];

if ($txtFiles) {
    foreach ($txtFiles as $filepath) {
        $filename = basename($filepath);
        $meta     = parseMatitaHeader($filepath);

        if ($meta['name'] === '') {
            $meta['name'] = pathinfo($filename, PATHINFO_FILENAME);
        }

        if (!empty($meta['category'])) {
            foreach ($meta['category'] as $catName) {
                $uniqueCategories[$catName] = true;
            }
        }
        
        if ($meta['language'] !== '') {
            $uniqueLanguages[$meta['language']] = true;
        }

        $programs[] = [
            'file'        => $filename,
            'name'        => $meta['name'],
            'category'    => $meta['category'],
            'version'     => $meta['version'],
            'date'        => $meta['date'],
            'author'      => $meta['author'],
            'language'    => $meta['language'],
            'matita_ver'  => $meta['matita_ver'],
            'description' => $meta['description'],
        ];
    }

    usort($programs, fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
}

$availableCategories = array_keys($uniqueCategories);
usort($availableCategories, 'strcasecmp');

$availableLanguages = array_keys($uniqueLanguages);
usort($availableLanguages, 'strcasecmp');

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Example Programs in Matita</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&family=Roboto+Mono:wght@400;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --blue:   #007BFF;
            --blue2:  #0056b3;
            --green:  #28a745;
            --border: #dee2e6;
            --text:   #212529;
            --muted:  #6c757d;
            --W:      1020px;
        }
        *, *::before, *::after { box-sizing: border-box; }
        html, body {
            margin: 0; padding: 0;
            background: #fff;
            color: var(--text);
            font-family: 'Poppins', sans-serif;
            line-height: 1.6;
        }

        /* ── Header ── */
        header {
            position: sticky; top: 0;
            background: #fff;
            border-bottom: 1px solid var(--border);
            z-index: 10;
        }
        header .bar {
            max-width: var(--W);
            margin: 0 auto;
            padding: 12px 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        header .logo { font-weight: 700; color: var(--blue); font-size: 1.1rem; }
        header .sub  { font-size: 0.8rem; color: var(--muted); }

        main { max-width: var(--W); margin: 0 auto; padding: 32px 20px 60px; }

        h1 { font-size: 2rem; color: var(--blue); margin-bottom: 0.25rem; }
        .lead { color: var(--muted); margin-bottom: 2rem; font-size: 0.92rem; }

        /* ── Count badge ── */
        .count-badge {
            display: inline-flex; align-items: center;
            background: #eef4ff; border: 1px solid #c7d9f9;
            color: var(--blue2); border-radius: 20px;
            font-size: 0.78rem; font-weight: 600;
            padding: 2px 12px; margin-left: 10px;
            vertical-align: middle;
        }

        /* ── Search bar & Filters ── */
        .search-wrap { 
            display: flex; 
            gap: 12px; 
            margin-bottom: 1.25rem; 
            flex-wrap: wrap;
        }
        #searchInput {
            flex: 1;
            min-width: 200px;
            max-width: 420px;
            padding: 8px 14px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-family: 'Poppins', sans-serif;
            font-size: 0.88rem;
            outline: none;
            transition: border-color .2s, box-shadow .2s;
        }
        #searchInput:focus, #categorySelect:focus, #languageSelect:focus {
            border-color: var(--blue);
            box-shadow: 0 0 0 3px rgba(0,123,255,.15);
        }
        #categorySelect, #languageSelect {
            padding: 8px 14px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-family: 'Poppins', sans-serif;
            font-size: 0.88rem;
            outline: none;
            background: #fff;
            cursor: pointer;
            transition: border-color .2s, box-shadow .2s;
            color: var(--text);
        }

        /* ── Table ── */
        .wrap {
            overflow-x: auto;
            border-radius: 10px;
            border: 1px solid var(--border);
            box-shadow: 0 2px 8px rgba(0,0,0,.06);
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
            table-layout: fixed;
        }

        /* Column widths */
        col.col-name { width: 30%; }
        col.col-desc { /* fills remaining space */ }
        col.col-act  { width: 140px; }

        thead tr {
            background: #1e293b;
            color: #f1f5f9;
        }
        thead th {
            padding: 12px 14px;
            text-align: left;
            font-weight: 600;
            white-space: nowrap;
        }
        tbody tr { border-bottom: 1px solid #f0f0f0; transition: background .1s; }
        tbody tr:last-child { border-bottom: none; }
        tbody tr:nth-child(even) { background: #fafbfc; }
        tbody tr:hover { background: #eef4ff; }
        tbody td { padding: 10px 14px; vertical-align: top; }

        /* ── First column: name block ── */
        .prog-title {
            font-weight: 600;
            color: var(--text);
            margin-bottom: 3px;
            word-break: break-word;
        }
        .td-file {
            font-family: 'Roboto Mono', monospace;
            font-size: 0.73rem;
            color: #0369a1;
            word-break: break-all;
            margin-bottom: 5px;
        }

        /* ── Meta pills under filename ── */
        .meta-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            margin-top: 4px;
        }
        .pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 0.67rem;
            font-weight: 600;
            padding: 2px 7px;
            border-radius: 20px;
            white-space: nowrap;
            letter-spacing: .02em;
        }
        .pill-cat {
            background: #fff3e0;
            color: #e65100;
            border: 1px solid #ffe0b2;
        }
        .pill-lang {
            background: #e8f4fd;
            color: #0369a1;
            border: 1px solid #bae0f9;
        }
        .pill-ver {
            background: #eef4ff;
            color: var(--blue2);
            border: 1px solid #c7d9f9;
        }
        .pill-ver.empty {
            background: #f1f5f9;
            color: #94a3b8;
            border: 1px solid #e2e8f0;
        }

        /* ── Description column ── */
        .prog-body {
            color: var(--muted);
            font-size: 0.82rem;
            line-height: 1.5;
        }

        /* ── Action column ── */
        .act-wrap { display: flex; gap: 6px; align-items: center; flex-wrap: nowrap; white-space: nowrap; }
        .btn {
            display: inline-flex; align-items: center; gap: 4px;
            border: none; cursor: pointer;
            font-family: 'Poppins', sans-serif;
            font-size: 0.76rem; font-weight: 600;
            padding: 5px 12px; border-radius: 5px;
            transition: background .15s, transform .1s;
            white-space: nowrap; text-decoration: none;
        }
        .btn:active { transform: translateY(1px); }
        .btn-copy { background: var(--blue); color: #fff; }
        .btn-copy:hover { background: var(--blue2); }
        .btn-copy.ok  { background: var(--green); }
        .btn-copy.err { background: #dc3545; }
        .btn-dl {
            background: #f1f5f9; color: #334155;
            border: 1px solid #cbd5e1;
            font-size: 0.85rem; padding: 4px 9px;
        }
        .btn-dl:hover { background: #e2e8f0; }

        /* ── Empty / no-results ── */
        .empty-row td {
            text-align: center;
            padding: 40px 20px;
            color: var(--muted);
            font-size: 0.9rem;
        }
        #noResults {
            display: none;
            text-align: center;
            padding: 32px;
            color: var(--muted);
            font-size: 0.9rem;
        }
    </style>
</head>
<body>

<header>
    <div class="bar">
        <span class="logo">Matita</span>
        <span class="sub">Example Programs</span>
    </div>
</header>

<main>
    <h1>
        Example Programs
        <span class="count-badge">
            <?= count($programs) ?> file<?= count($programs) !== 1 ? 's' : '' ?>
        </span>
    </h1>
    <p class="lead">
        Collection of programs written in Matita.
        Click <strong>Copy</strong> to copy the source to the clipboard,
        or <strong>↓</strong> to download the <code>.txt</code> file.
    </p>

    <?php if (count($programs) > 5): ?>
    <div class="search-wrap">
        <input
            type="search"
            id="searchInput"
            placeholder="🔍  Filter by name, description…"
            autocomplete="off"
        >
        <select id="categorySelect">
            <option value="">All Categories</option>
            <?php foreach ($availableCategories as $cat): ?>
                <option value="<?= e(strtolower($cat)) ?>"><?= e($cat) ?></option>
            <?php endforeach; ?>
        </select>
        <select id="languageSelect">
            <option value="">All Languages</option>
            <?php foreach ($availableLanguages as $lang): ?>
                <option value="<?= e(strtolower($lang)) ?>"><?= e($lang) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>

    <div class="wrap">
        <table>
            <colgroup>
                <col class="col-name">
                <col class="col-desc">
                <col class="col-act">
            </colgroup>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Description</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="prog-table">

            <?php if (empty($programs)): ?>
                <tr class="empty-row">
                    <td colspan="3">No <code>.txt</code> files found in this directory.</td>
                </tr>

            <?php else: ?>
                <?php foreach ($programs as $p): ?>
                <tr
                    data-name="<?= e(strtolower($p['name'])) ?>"
                    data-desc="<?= e(strtolower($p['description'])) ?>"
                    data-lang="<?= e(strtolower($p['language'])) ?>"
                    data-file="<?= e(strtolower($p['file'])) ?>"
                    data-categories="|<?= e(implode('|', array_map('strtolower', $p['category']))) ?>|"
                >
                    <td>
                        <div class="prog-title"><?= e($p['name']) ?></div>
                        <div class="td-file"><?= e($p['file']) ?></div>
                        <div class="meta-pills">
                            <?php foreach ($p['category'] as $catName): ?>
                                <span class="pill pill-cat">&#128193; <?= e($catName) ?></span>
                            <?php endforeach; ?>
                            
                            <?php if ($p['language'] !== ''): ?>
                                <span class="pill pill-lang">&#127760; <?= e($p['language']) ?></span>
                            <?php endif; ?>
                            
                            <?php if ($p['matita_ver'] !== ''): ?>
                                <span class="pill pill-ver">min <?= e($p['matita_ver']) ?></span>
                            <?php else: ?>
                                <span class="pill pill-ver empty">ver —</span>
                            <?php endif; ?>
                        </div>
                    </td>

                    <td>
                        <div class="prog-body"><?= e($p['description']) ?></div>
                    </td>

                    <td>
                        <div class="act-wrap">
                            <button
                                class="btn btn-copy"
                                data-file="<?= e($p['file']) ?>"
                                onclick="copyCode(this)"
                            >Copy</button>
                            <a
                                class="btn btn-dl"
                                href="?action=download&amp;file=<?= urlencode($p['file']) ?>"
                                title="Download <?= e($p['file']) ?>"
                                download
                            >&#8595;</a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>

            </tbody>
        </table>
        <div id="noResults">No programs match your search criteria.</div>
    </div>
</main>

<script>
// ── Copy handler ──────────────────────────────────────────────
async function copyCode(btn) {
    const file    = btn.dataset.file;
    const origTxt = btn.textContent;
    btn.textContent = '\u23F3\u2026';
    btn.disabled    = true;

    try {
        const res = await fetch('?action=raw&file=' + encodeURIComponent(file));
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const text = await res.text();
        await navigator.clipboard.writeText(text);
        btn.textContent = '\u2713 Copied';
        btn.classList.add('ok');
    } catch (err) {
        console.error(err);
        btn.textContent = '\u2717 Error';
        btn.classList.add('err');
    } finally {
        btn.disabled = false;
        setTimeout(() => {
            btn.textContent = origTxt;
            btn.classList.remove('ok', 'err');
        }, 2200);
    }
}

// ── Live search & Filter ──────────────────────────────────────
const searchInput = document.getElementById('searchInput');
const categorySelect = document.getElementById('categorySelect');
const languageSelect = document.getElementById('languageSelect');

function filterPrograms() {
    const q    = searchInput ? searchInput.value.trim().toLowerCase() : '';
    const cat  = categorySelect ? categorySelect.value : '';
    const lang = languageSelect ? languageSelect.value : '';
    const rows = document.querySelectorAll('#prog-table tr[data-name]');
    let visible = 0;

    rows.forEach(function (row) {
        // La ricerca di testo avviene su nome, descrizione e file
        const haystack = [
            row.dataset.name,
            row.dataset.desc,
            row.dataset.file
        ].join(' ');

        const matchesSearch   = q === '' || haystack.includes(q);
        const matchesCategory = cat === '' || row.dataset.categories.includes('|' + cat + '|');
        const matchesLanguage = lang === '' || row.dataset.lang === lang;

        const show = matchesSearch && matchesCategory && matchesLanguage;
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    const noRes = document.getElementById('noResults');
    if (noRes) {
        noRes.style.display = (visible === 0 && (q !== '' || cat !== '' || lang !== '')) ? 'block' : 'none';
    }
}

if (searchInput) searchInput.addEventListener('input', filterPrograms);
if (categorySelect) categorySelect.addEventListener('change', filterPrograms);
if (languageSelect) languageSelect.addEventListener('change', filterPrograms);
</script>
</body>
</html>