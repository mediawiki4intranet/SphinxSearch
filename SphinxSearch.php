<?php

/**
 * SphinxSearch extension code for MediaWiki
 *
 * http://www.mediawiki.org/wiki/Extension:SphinxSearch
 * http://wiki.4intra.net/Mediawiki4Intranet
 *
 * Developed by Paul Grinberg and Svemir Brkic
 * Adjusted by Vitaliy Filippov and Stas Fomin
 *
 * Released under GNU General Public License (see http://www.fsf.org/licenses/gpl.html)
 */

if (!defined('MEDIAWIKI'))
{
    echo("This file is an extension to the MediaWiki software and cannot be used standalone.\n");
    die(1);
}

$wgExtensionCredits['specialpage'][] = array(
    'version'     => '0.6.1',
    'name'        => 'SphinxSearch',
    'author'      => 'Svemir Brkic, Paul Grinberg, Vitaliy Filippov, Stas Fomin',
    'email'       => 'svemir at thirdblessing dot net, gri6507 at yahoo dot com',
    'url'         => 'http://www.mediawiki.org/wiki/Extension:SphinxSearch',
    'description' => 'Replace MediaWiki search engine with [http://www.sphinxsearch.com/ Sphinx].'
);

# Host and port on which searchd deamon is tunning
if (!$wgSphinxSearch_host)
    $wgSphinxSearch_host = 'localhost';
if (!$wgSphinxSearch_port)
    $wgSphinxSearch_port = 3312;
# Main sphinx.conf index to search
if (!$wgSphinxSearch_index)
    $wgSphinxSearch_index = "wiki_main";

# Default Sphinx search mode
if (!$wgSphinxSearch_mode)
    $wgSphinxSearch_mode = SPH_MATCH_EXTENDED;

# By default, search will return articles that match any of the words in the search
# To change that to require all words to match by default, uncomment the next line
#$wgSphinxMatchAll = 1;

# Number of matches to display at once
if (!$wgSphinxSearch_matches)
    $wgSphinxSearch_matches = 10;
# How many matches searchd will keep in RAM while searching
if (!$wgSphinxSearch_maxmatches)
    $wgSphinxSearch_maxmatches = 1000;
# When to stop searching all together (if different from zero)
if (!is_int($wgSphinxSearch_cutoff))
    $wgSphinxSearch_cutoff = 0;

# Weights of individual indexed columns. This gives page titles extra weight
$wgSphinxSearch_weights = array('old_text' => 1, 'page_title' => 100);

# If you want to enable hierarchical category search, specify the top category of your hierarchy here
#$wgSphinxTopSearchableCategory = 'Subject_areas';

# If you want sub-categories to be fetched as parent categories are checked,
# also set $wgUseAjax to true in your LocalSettings file, so that the following can be used:
$wgAjaxExportList[] = 'SphinxSearch::ajaxGetCategoryChildren';

# Web-accessible path to the extension's folder
if (!$wgSphinxSearchExtPath)
    $wgSphinxSearchExtPath = '/extensions/SphinxSearch';
# Web-accessible path to the folder with SphinxSearch.js file (if different from $wgSphinxSearchExtPath)
#$wgSphinxSearchJSPath = '';

##########################################################
# Use Aspell to suggest possible misspellings. This could be provided via either
# PHP pspell module (http://www.php.net/manual/en/ref.pspell.php) or command line
# insterface to ASpell

# Should the suggestion mode be enabled?
if (!is_bool($wgSphinxSuggestMode))
    $wgSphinxSuggestMode = true;

# Path to where aspell has location and language data files. Leave commented out if unsure
#$wgSphinxSearchPspellDictionaryDir = "/usr/lib/aspell";

# Path to personal dictionary. Needed only if using a personal dictionary
#$wgSphinxSearchPersonalDictionary = dirname( __FILE__ ) . "/personal.en.pws";

# Path to Aspell. Needed only if using command line interface instead of the PHP built in PSpell interface.
#$wgSphinxSearchAspellPath = "/usr/bin/aspell";
# End of Suggest Mode configuration options
##########################################################

##########################################################
# To completely disable the default search and replace it with ours, uncomment these three lines
$wgDisableInternalSearch = true;
$wgDisableSearchUpdate = true;
$wgSearchType = 'SphinxSearch';
# Above three lines should be uncommented to make SphinxSearch the default
##########################################################

$dir = dirname(__FILE__);

$wgAutoloadClasses += array(
    // this assumes you have copied sphinxapi.php from your Sphinx
    // installation folder to your SphinxSearch extension folder
    'SphinxClient'              => "$dir/sphinxapi.php",
    'SphinxSearch_spell'        => "$dir/SphinxSearch_spell.php",
    'SphinxSearch'              => "$dir/SphinxSearch_body.php",
    'SphinxSearchPersonalDict'  => "$dir/SphinxSearch_PersonalDict.php",
);

$wgSpecialPages[($wgDisableInternalSearch ? 'Search' : 'SphinxSearch')] = 'SphinxSearch';
$wgSpecialPageGroups['SphinxSearch'] = 'search';

$wgHooks['SphinxSearchGetSearchableCategories'][] = 'efSphinxSearchGetSearchableCategories';
$wgExtensionMessagesFiles['SphinxSearch'] = dirname(__FILE__) . '/SphinxSearch.i18n.php';

if ($wgSphinxSuggestMode && $wgSphinxSearchPersonalDictionary)
{
    $wgSpecialPages['SphinxSearchPersonalDict'] = 'SphinxSearchPersonalDict';
    $wgSpecialPageGroups['SphinxSearchPersonalDict'] = 'search';
}
