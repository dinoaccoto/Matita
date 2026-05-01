<?php
// Aumentiamo la memoria disponibile per impedire il collasso sulle Regex con testi lunghi
ini_set('pcre.backtrack_limit', '100000000');
ini_set('pcre.recursion_limit', '100000000');

/**
 * Evidenzia la sintassi del linguaggio Matita
 */
function highlight_matita($code) {
    $code = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    
    $keywords = [
        'scrivi', 'accapo', 'metti', 'in', 'chiedi', 'dicendo', 'cancella schermo', 
        'cancella grafica', 'dipingi sfondo', 'se', 'allora', 'altrimenti', 'fintanto', 
        'esegui', 'ripeti', 'volte', 'indice', 'va', 'da', 'a', 'ed', 'stop', 'globale', 
        'alias', 'punto', 'imposta', 'colore', 'cerchio', 'raggio', 'circonferenza', 
        'spessore', 'linea', 'triangolo', 'con', 'vertici', 'arco', 'graffiti', 
        'grandezza', 'etichetta', 'come', 'importa', 'disegna', 'scala', 'ruota', 
        'gradi', 'posiziona', 'suono', 'play', 'volume', 'dissolvenza', 'uscita', 
        'sta suonando', 'lista', 'record', 'classe', 'estende', 'questo', 'SOVRA', 
        'classedi', 'set', 'dimensione di', 'elemento', 'di', 'aggiungi', 'inserisci', 
        'rimuovi', 'ha chiave', 'chiavi di', 'scomponi', 'dividi', 'scomposizione', 
        'composizione', 'cast', 'unicode', 'tipo', 'numerico', 'vero', 'falso', 'e', 
        'o', 'non', 'uguale a', 'vale', 'diverso da', 'mod', 'modulo', 'definisci', 
        'procedura', 'funzione', 'restituisci', 'inout', 'null', 'tick', 'tock'
    ];

    // Ordiniamo per lunghezza decrescente per evitare conflitti tra parole composte
    usort($keywords, function($a, $b) { return strlen($b) - strlen($a); });

    $pattern = '/(#.*|\/\/.*)|(&quot;.*?&quot;)|(\b(?:' . implode('|', $keywords) . ')\b)|(\b\d+(?:\.\d+)?\b)/i';
    
    return preg_replace_callback($pattern, function($m) {
        if (!empty($m[1])) return '<span class="com">' . $m[1] . '</span>';
        if (!empty($m[2])) return '<span class="str">' . $m[2] . '</span>';
        if (!empty($m[3])) {
            $kw = $m[3];
            // Se la parola chiave è lunga un solo carattere ed è MAIUSCOLA (es. "A", "O", "E"), 
            // la trattiamo come variabile testuale normale e NON la coloriamo.
            if (strlen($kw) === 1 && ctype_upper($kw)) {
                return $kw;
            }
            // Altrimenti coloriamo come comando
            return '<span class="kw">' . $kw . '</span>';
        }
        if (!empty($m[4])) return '<span class="num">' . $m[4] . '</span>';
        return $m[0];
    }, $code);
}

/**
 * Funzioni di sicurezza per evitare stringhe nulle se la Regex salta
 */
function safe_preg_replace($pattern, $replacement, $subject) {
    $result = preg_replace($pattern, $replacement, $subject);
    return $result === null ? $subject : $result;
}

function safe_preg_replace_callback($pattern, $callback, $subject) {
    $result = preg_replace_callback($pattern, $callback, $subject);
    return $result === null ? $subject : $result;
}

/**
 * Legge il file TXT e genera l'HTML completo
 */
function genera_html_da_txt($filepath) {
    $text = file_get_contents($filepath);
    if ($text === false) return "<p>Errore: File TXT non trovato.</p>";

    $blocks = [];
    
    // 1. Estrazione Sicura Blocchi <e> (Aggiunge il pulsante Copia)
    $text = safe_preg_replace_callback('/<e>\s*(.*?)\s*<\/e>/is', function($m) use (&$blocks) {
        $id = "@@BLOCK_E_" . uniqid() . "@@";
        $highlighted_code = highlight_matita($m[1]);
        
        $blocks[$id] = '
        <div class="code-wrapper">
            <button class="copy-btn" onclick="copyCode(this)" title="Copia codice">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg> Copia
            </button>
            <pre class="code"><code>' . $highlighted_code . '</code></pre>
        </div>';
        return $id;
    }, $text);

    $text = safe_preg_replace_callback('/<o>\s*(.*?)\s*<\/o>/is', function($m) use (&$blocks) {
        $id = "@@BLOCK_O_" . uniqid() . "@@";
        $blocks[$id] = '<pre class="output"><code>' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . '</code></pre>';
        return $id;
    }, $text);

    $text = safe_preg_replace_callback('/<n>\s*(.*?)\s*<\/n>/is', function($m) use (&$blocks) {
        $id = "@@BLOCK_N_" . uniqid() . "@@";
        $blocks[$id] = '<div class="note">' . nl2br(trim($m[1])) . '</div>';
        return $id;
    }, $text);

    // 2. Generatore Indice (TOC) Dinamico
    $toc = [];
    $text = safe_preg_replace_callback('/<(h[123])>(.*?)<\/\1>/is', function($m) use (&$toc) {
        $level = intval(substr($m[1], 1));
        $title = strip_tags($m[2]); 
        $id = "sez_" . count($toc);
        $toc[] = ['level' => $level, 'title' => $title, 'id' => $id];
        return "<{$m[1]} id=\"{$id}\">{$m[2]}</{$m[1]}>";
    }, $text);

    $tocHtml = '';
    if (!empty($toc)) {
        $tocHtml = '<nav aria-label="Indice dei contenuti"><ol>';
        $currentLevel = 1;
        foreach ($toc as $index => $item) {
            $lvl = $item['level'];
            if ($lvl > $currentLevel) {
                $tocHtml .= str_repeat("\n<ol>\n", $lvl - $currentLevel);
            } elseif ($lvl < $currentLevel) {
                $tocHtml .= str_repeat("</li>\n</ol>\n", $currentLevel - $lvl);
                $tocHtml .= "</li>\n";
            } else {
                if ($index > 0) $tocHtml .= "</li>\n";
            }
            $tocHtml .= '<li><a href="#' . $item['id'] . '">' . $item['title'] . '</a>';
            $currentLevel = $lvl;
        }
        $tocHtml .= str_repeat("</li>\n</ol>\n", $currentLevel - 1) . "</li>\n</ol></nav>";
    }

    // 3. Sostituzioni Inline Semplici
    $text = safe_preg_replace('/<i>(.*?)<\/i>/is', '<em>$1</em>', $text);
    $text = safe_preg_replace('/<b>(.*?)<\/b>/is', '<strong>$1</strong>', $text);
    $text = safe_preg_replace('/<u>(.*?)<\/u>/is', '<u>$1</u>', $text);
    $text = safe_preg_replace('/<c>(.*?)<\/c>/is', '<code>$1</code>', $text);
    $text = safe_preg_replace('/<tag\s*=\s*([^>]+)>(.*?)<\/tag>/is', '<a href="$1" target="_blank">$2</a>', $text);
    $text = safe_preg_replace('/<ref\s*=\s*([^>]+)>(.*?)<\/ref>/is', '<a href="#$1">$2</a>', $text);
    $text = safe_preg_replace('/<figure\s+([^:]+):([^:]+):([^>]+)>/i', '<figure style="text-align: $3; margin: 16px 0;"><img src="$1" style="max-width: $2; height: auto; border-radius: 8px;"></figure>', $text);
    
    $text = str_replace('<vspace>', '<div style="height: 24px;"></div>', $text);
    $text = str_replace('<hl>', '<div class="pagebreak"></div>', $text);
    
    $text = safe_preg_replace('/<indent>\s*(?=<#|<[\*])/', '', $text);
    $text = str_replace('<indent>', '<span style="margin-left: 20px; display:inline-block;"></span>', $text);

    // 4. Liste Puntate e Numerate
    $text = str_replace(['<***>', '</***>'], ['<ul class="lvl3"><li>', '</li></ul>'], $text);
    $text = str_replace(['<**>', '</**>'],   ['<ul class="lvl2"><li>', '</li></ul>'], $text);
    $text = str_replace(['<*>', '</*>'],     ['<ul class="lvl1"><li>', '</li></ul>'], $text);

    $text = str_replace(['<###>', '</###>'], ['<ol class="lvl3"><li>', '</li></ol>'], $text);
    $text = str_replace(['<##>', '</##>'],   ['<ol class="lvl2"><li>', '</li></ol>'], $text);
    $text = str_replace(['<#>', '</#>'],     ['<ol class="lvl1"><li>', '</li></ol>'], $text);

    $text = safe_preg_replace('/<\/ul>\s*<ul class="lvl3"><li>/s', '</li><li>', $text);
    $text = safe_preg_replace('/<\/ul>\s*<ul class="lvl2"><li>/s', '</li><li>', $text);
    $text = safe_preg_replace('/<\/ul>\s*<ul class="lvl1"><li>/s', '</li><li>', $text);
    $text = safe_preg_replace('/<\/ol>\s*<ol class="lvl3"><li>/s', '</li><li>', $text);
    $text = safe_preg_replace('/<\/ol>\s*<ol class="lvl2"><li>/s', '</li><li>', $text);
    $text = safe_preg_replace('/<\/ol>\s*<ol class="lvl1"><li>/s', '</li><li>', $text);

    // 5. Gestione intelligente delle andate a capo (nl2br generico)
    $text = nl2br($text);

    // 6. Ripristino dei blocchi protetti PRIMA del cleanup dei <br> (Fondamentale!)
    foreach ($blocks as $id => $htmlBlock) {
        $text = str_replace($id, $htmlBlock, $text);
    }

    // 7. Pulizia spietata dei <br> in eccesso creati da nl2br attorno ai blocchi strutturali
    $blockTags = 'ul|ol|li|h1|h2|h3|h4|div|figure|hr|table|thead|tbody|tr|td|th|pre|nav|aside|header|main|section|p';
    $text = safe_preg_replace('/(<(?:'.$blockTags.')\b[^>]*>)\s*(?:<br\s*\/?>\s*)+/i', '$1', $text);
    $text = safe_preg_replace('/(?:\s*<br\s*\/?>\s*)+(<\/(?:'.$blockTags.')>)/i', '$1', $text);
    $text = safe_preg_replace('/(?:\s*<br\s*\/?>\s*)+(<(?:'.$blockTags.')\b[^>]*>)/i', '$1', $text);
    $text = safe_preg_replace('/(<\/(?:'.$blockTags.')>)\s*(?:<br\s*\/?>\s*)+/i', '$1', $text);

    // 8. Assemblaggio del Template HTML Definitivo
    $htmlOutput = <<<HTML
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Matita v1.0.0 - Guida Completa</title>
    <meta name="author" content="Dino Accoto" />
    <meta name="description" content="Una guida completa per programmare con Matita v1.0.0." />
    <meta name="subject" content="Guida operativa alla programmazione con Matita (sintassi italiana)" />
    <meta name="keywords" content="programmazione, grafica, Matita, cicli, variabili, interattività, liste, dizionari, record, audio, alias, inout, null, procedure, funzioni, scope, globale, etichette, dimensione, accapo, scrivi, cast, unicode" />
    
    <style>
        :root {
            --PrimaryBlue: #007BFF;
            --PrimaryYellow: #FFC107;
            --White: #fff;
            --DarkText: #212529;
            --CodeBackground: #1e293b;
            --CodeText: #f8fafc;
            --SidebarBg: #0f172a;
            --PaperW: 900px;
        }

        html, body {
            margin: 0; padding: 0; background: #fff; color: var(--DarkText);
            font-family: system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, Cantarell, Noto Sans, Helvetica, Arial, sans-serif;
            line-height: 1.7;
        }

        /* --- LAYOUT CON MENU A SINISTRA --- */
        @media (min-width: 1024px) {
            body { padding-left: 320px; }
            .sidebar {
                position: fixed; top: 0; left: 0; width: 320px; height: 100vh;
                background: var(--SidebarBg); color: #cbd5e1; overflow-y: auto;
                padding: 30px 20px; box-sizing: border-box; box-shadow: 4px 0 15px rgba(0,0,0,0.1); z-index: 20;
            }
            header { width: 100%; }
        }

        @media (max-width: 1023px) {
            .sidebar { width: 100%; background: var(--SidebarBg); color: #cbd5e1; padding: 20px; box-sizing: border-box; }
            header { width: 100%; }
        }

        .sidebar h2 { color: #fff; font-size: 1.4rem; margin-top: 0; margin-bottom: 1.5rem; border-bottom: 1px solid #334155; padding-bottom: 10px; }
        .sidebar ol { padding-left: 15px; list-style-type: decimal; margin-bottom: 1.5rem; }
        .sidebar li { margin-bottom: 8px; }
        .sidebar li > ol { margin-top: 6px; list-style-type: disc; padding-left: 20px; font-size: 0.95em; }
        .sidebar a { color: #94a3b8; text-decoration: none; transition: color 0.2s; }
        .sidebar a:hover { color: #38bdf8; text-decoration: underline; }

        header { position: sticky; top: 0; background: white; border-bottom: 1px solid #e5e7eb; z-index: 10; }
        header .bar { max-width: var(--PaperW); margin: 0 auto; display: flex; align-items: center; justify-content: space-between; padding: 10px 16px; }
        header .left { font-weight: 700; color: var(--PrimaryBlue); }
        header .right { color: #6b7280; }

        main { max-width: var(--PaperW); margin: 0 auto; padding: 24px 32px; }

        h1, h2, h3, h4 { line-height: 1.25; }
        h1 { font-size: 2.2rem; margin: 24px 0 8px; }
        h2 { font-size: 1.7rem; margin: 32px 0 12px; border-bottom: 2px solid #f3f4f6; padding-bottom: 6px; color: #111827; }
        h3 { font-size: 1.25rem; margin: 24px 0 8px; color: #1f2937; }

        .title { display: flex; flex-direction: column; align-items: center; text-align: center; background: var(--White); border: 1px solid #e5e7eb; border-radius: 12px; padding: 24px; margin-bottom: 30px; }
        .title img { max-width: 45%; height: auto; border-radius: 8px; }
        
        code, .code { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
        code:not(pre code) { color: #0369a1; background-color: #f1f5f9; padding: 2px 6px; border-radius: 4px; font-size: 0.9em; border: 1px solid #e2e8f0; }

        /* ==================================================
           MAGIA CSS: FUSIONE INTELLIGENTE DI CODE E OUTPUT 
           ================================================== */
           
        /* 1. Stile Base del blocco Codice (Arrotondato su tutti i lati) */
        .code-wrapper { position: relative; margin: 16px 0; }
        pre.code { 
            background: var(--CodeBackground); padding: 16px; padding-top: 40px; 
            border-radius: 10px; overflow: auto; border: 1px solid #475569; 
            margin: 0; box-shadow: inset 0 2px 4px rgba(0,0,0,0.2); 
        }
        pre.code code { font-size: 0.95rem; color: var(--CodeText); background: transparent; padding: 0; border: none; }
        
        /* Pulsante Copia */
        .copy-btn {
            position: absolute; top: 8px; right: 8px; background: #334155; color: #f8fafc;
            border: 1px solid #475569; border-radius: 6px; padding: 4px 8px;
            font-size: 0.8rem; cursor: pointer; display: flex; align-items: center; gap: 4px;
            opacity: 0.7; transition: all 0.2s; font-family: inherit;
        }
        .copy-btn:hover { opacity: 1; background: #475569; }
        .copy-btn.copied { background: #10b981; color: white; border-color: #10b981; opacity: 1; }

        /* 2. Stile Base del blocco Output (Arrotondato su tutti i lati) */
        pre.output { 
            background: #f8fafc; padding: 12px 16px; border-radius: 10px; 
            border: 1px solid #cbd5e1; margin: 16px 0; 
        }
        pre.output code { font-size: 0.95rem; color: #334155; background: transparent; padding: 0; border: none; }

        /* 3. FUSIONE: Se un Output segue ESATTAMENTE un Code-wrapper, li incolliamo */
        .code-wrapper:has(+ pre.output) { margin-bottom: 0; }
        .code-wrapper:has(+ pre.output) pre.code {
            border-bottom-left-radius: 0;
            border-bottom-right-radius: 0;
            border-bottom: none;
        }
        .code-wrapper + pre.output {
            margin-top: 0;
            border-top-left-radius: 0;
            border-top-right-radius: 0;
            border-top: 1px dashed #94a3b8;
        }

        /* ================================================== */

        .kw { color: #38bdf8; font-weight: 600; }
        .str { color: #fbbf24; }
        .num { color: #a78bfa; }
        .com { color: rgba(148, 163, 184, 0.85); font-style: italic; }

        figure { margin: 16px 0; text-align: center; }
        .note { background: #f8fafc; border-left: 4px solid var(--PrimaryBlue); padding: 14px 18px; border-radius: 0 8px 8px 0; margin: 20px 0; }
        .badge { display: inline-block; background: #10b981; color: white; font-size: 0.7rem; padding: 2px 6px; border-radius: 12px; vertical-align: middle; margin-left: 8px; text-transform: uppercase; font-weight: 700; }

        table { border-collapse: collapse; width: 100%; margin: 16px 0; font-size: 0.95rem; }
        th, td { border: 1px solid #e5e7eb; padding: 10px 12px; vertical-align: top; text-align: left; }
        th { background: #f8fafc; color: #334155; }
        tr:nth-child(even) { background-color: #f9fafb; }

        footer { max-width: var(--PaperW); margin: 24px auto 40px; color: #6b7280; text-align: center; }
        .chip { display: inline-block; background: #eef2ff; color: #3730a3; border: 1px solid #e0e7ff; padding: 2px 8px; border-radius: 999px; font-size: .85rem; margin: 2px; }

        .pagebreak { height: 1px; background: #e5e7eb; margin: 40px 0; border: none; }
        .top-links { text-align: center; margin-bottom: 30px; font-weight: 600; font-size: 1.2rem; }
        .top-links a { text-decoration: none; color: var(--PrimaryBlue); transition: color 0.2s; }
        .top-links a:hover { text-decoration: underline; color: #0056b3; }
        .separator { margin: 0 10px; color: #cbd5e1; }
        
        ul.lvl1, ol.lvl1 { padding-left: 20px; margin-bottom: 0; margin-top: 4px; }
        ul.lvl2, ol.lvl2 { padding-left: 20px; list-style-type: circle; margin-bottom: 0; margin-top: 4px; }
        ul.lvl3, ol.lvl3 { padding-left: 20px; list-style-type: square; margin-bottom: 0; margin-top: 4px; }
    </style>
</head>
<body>

<header>
    <div class="bar">
        <div class="left">Matita v1.0.0</div>
        <div class="right">Guida per l'Utente</div>
    </div>
</header>

<aside class="sidebar">
    <h2>Indice dei contenuti</h2>
    {$tocHtml}
</aside>

<main>
    <div class="top-links">
        <a href="storiaIT.html" target="_blank">Motivazione</a>
        <span class="separator">&mdash;</span>
        <a href="infoIT.html" target="_blank">Caratteristiche</a>
        <span class="separator">&mdash;</span>
        <a href="../Ex/examples.php" target="_blank">Programmi di esempio</a>
    </div>

    <section class="title" id="top">
        <h1><strong>Programmare in Matita</strong></h1>
        <figure>
            <img src="../graphics/matita.png" alt="Logo Matita" />
        </figure>
    </section>

    <div class="pagebreak"></div>

    {$text}

</main>

<footer>
    <span class="chip">Matita v1.0.0</span>
    <span class="chip">Guida (Informatica)</span>
</footer>

<figure style="max-width: var(--PaperW); margin: 18px auto 40px; text-align: center;">
    <img src="../graphics/coolcoder.png" alt="Cool Coder" style="max-width: 520px; width: 90%; height: auto; border-radius: 12px; border: 1px solid #e5e7eb;" />
</figure>

<script>
function copyCode(btn) {
    const codeBlock = btn.parentElement.querySelector('code');
    const textToCopy = codeBlock.textContent;
    
    navigator.clipboard.writeText(textToCopy).then(() => {
        const originalHtml = btn.innerHTML;
        btn.innerHTML = '✅ Copiato!';
        btn.classList.add('copied');
        
        setTimeout(() => { 
            btn.innerHTML = originalHtml; 
            btn.classList.remove('copied');
        }, 2000);
    }).catch(err => {
        console.error('Errore nella copia: ', err);
    });
}
</script>

</body>
</html>
HTML;

    return $htmlOutput;
}

// ==========================================
// ESECUZIONE
// ==========================================
$txt_file = "text.txt"; 

if (file_exists($txt_file)) {
    echo genera_html_da_txt($txt_file);
} else {
    echo "<h1 style='text-align:center; color: #dc2626;'>Errore: File TXT non trovato</h1>";
}
?>