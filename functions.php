#!/usr/bin/env php
<?php
/**
 * @param $resDir
 * @param $url
 * @param $filesDir
 * @param $destDir
 */
function updateDocFiles($resDir, $url, $filesDir, $destDir)
{
    exec("rm -rf $resDir");
    exec("mkdir -p $resDir");
    exec("wget -q -F -p -P $resDir -k --reject \"*.js,*.txt\" $url");
    exec("cp $filesDir/icon.png $destDir/icon.png");
}

/**
 * @param $lang
 * @param $indexFile
 * @param $destDir
 */
function initPlistConfig($lang, $indexFile, $destDir)
{
    $plist = <<<HEREDOC
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
	<key>CFBundleIdentifier</key>
	<string>sphinxsearch-{$lang}</string>
	<key>CFBundleName</key>
	<string>Sphinx Search Server</string>
	<key>DocSetPlatformFamily</key>
	<string>sphinxsearch</string>
	<key>isDashDocset</key>
	<true/>
	<key>dashIndexFilePath</key>
	<string>$indexFile</string>
	<key>DashDocSetFamily</key>
    <string>dashtoc</string>
</dict>
</plist>
HEREDOC;

    file_put_contents("$destDir/Contents/Info.plist", $plist);
}

/**
 * @param $destDir
 * @return sqlite3
 */
function initDb($destDir)
{
    $db = new sqlite3("$destDir/Contents/Resources/docSet.dsidx");
    $db->query("DROP TABLE IF EXISTS searchIndex");
    $db->query("CREATE TABLE searchIndex (id INTEGER PRIMARY KEY, name TEXT, type TEXT, path TEXT)");
    $db->query("CREATE UNIQUE INDEX anchor ON searchIndex (name, type, path)");

    return $db;
}

/**
 * @param $crawler
 * @param $db
 * @param $urlPrefix
 */
function addGuides($crawler, $db, $urlPrefix)
{
    $els = $crawler->filter('.toc dl a');
    /** @var \DOMElement $el */
    foreach ($els as $el) {
        $db->query(
            "INSERT OR IGNORE INTO searchIndex(name, type, path) VALUES (\"$el->textContent\",\"Guide\",\"${urlPrefix}{$el->getAttribute(
                'href'
            )}\")"
        );
    }
}

/**
 * @param $resDir
 * @param $db
 * @param $urlPrefix
 */
function addFunctions($resDir, $db, $urlPrefix)
{
    $crawler = new Symfony\Component\DomCrawler\Crawler();
    $crawler->addHtmlContent(file_get_contents("$resDir/sphinxsearch.com/docs/current.html"));

    $els = $crawler->filter('a[href*="expr-ari-ops"]');

    /** @var \DOMElement $el */
    foreach ($els as $el) {
        $listCrawler = new \Symfony\Component\DomCrawler\Crawler($el->parentNode->parentNode);
        $links = $listCrawler->filter('a');
        /** @var \DOMElement $child */
        foreach ($links as $child) {
            $db->query(
                "INSERT OR IGNORE INTO searchIndex(name, type, path) VALUES (\"$child->textContent\",\"Function\",\"${urlPrefix}{$child->getAttribute(
                    'href'
                )}\")"
            );
        }
    }
}

/**
 * @param $resDir
 * @param $db
 * @param $urlPrefix
 */
function addLibraries($resDir, $db, $urlPrefix)
{
    $crawler = new Symfony\Component\DomCrawler\Crawler();
    $crawler->addHtmlContent(file_get_contents("$resDir/sphinxsearch.com/docs/current.html"));

    // Libraries
    $els = $crawler->filter('.toc .chapter a');

    /** @var \DOMElement $el */
    foreach ($els as $el) {
        $label = preg_replace('/[\d\.]+\s/', '', $el->textContent);
        $db->query(
            "INSERT OR IGNORE INTO searchIndex(name, type, path) " .
            "VALUES (\"{$label}\",\"Library\",\"${urlPrefix}{$el->getAttribute('href')}\")"
        );
    }
}

/**
 * @param $db
 * @param $urlPrefix
 * @param $crawler
 */
function addOptions($db, $urlPrefix, $crawler)
{
// Sphinx config
    $els = $crawler->filter('a[href*="#confgroup-source"]');

    /** @var \DOMElement $el */
    foreach ($els as $el) {
        $listCrawler = new \Symfony\Component\DomCrawler\Crawler($el->parentNode->parentNode->parentNode);
        $links = $listCrawler->filter('.sect2 a');
        /** @var \DOMElement $child */
        foreach ($links as $child) {
            $label = preg_replace('/[\d\.]+\s/', '', $child->textContent);
            $db->query(
                "INSERT OR IGNORE INTO searchIndex(name, type, path) VALUES (\"$label\",\"Option\",\"${urlPrefix}{$child->getAttribute(
                    'href'
                )}\")"
            );
        }
    }
}

/**
 * @param \Symfony\Component\DomCrawler\Crawler $crawler
 */
function addToc($crawler, $resDir)
{
    // Sphinx config
    $els = $crawler->filter('.titlepage h2 a[name]');
    $type = 'Category';
    $tpl = '<a name="//apple_ref/cpp/' . $type . '/{name}" class="dashAnchor"></a>';

    $search = $replace = [];
    /** @var \DOMElement $el */
    foreach ($els as $el) {
        $search[] = 'name="' . $el->getAttribute('name') . '"';
        $replace[] = $url = str_replace('{name}', rawurlencode($el->parentNode->textContent), $tpl);
    }

    $html = $crawler->html();

    foreach ($search as $k => $subpattern) {
        $html = preg_replace('~<a\s[^>]*' . preg_quote($subpattern, '~') . '.*</a>~iU', '$0' . $replace[$k], $html);
    }

    file_put_contents("$resDir/sphinxsearch.com/docs/current.html", $html);
}

/**
 * @param $resDir
 * @param $urlPrefix
 * @param $filename
 * @return \Symfony\Component\DomCrawler\Crawler
 */
function parseHtmlFile($resDir, $urlPrefix, $filename)
{
    $crawler = new Symfony\Component\DomCrawler\Crawler();
    $crawler->addHtmlContent(file_get_contents("$resDir/$urlPrefix/$filename"));

    return $crawler;
}