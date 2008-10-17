<?php

if( !defined( 'MEDIAWIKI' ) ) {
    echo( "This file is an extension to the MediaWiki software and cannot be used standalone.\n" );
    die( 1 );
}

$wgExtensionCredits['specialpage'][] = array(
    'version'     => '0.6.beta',
    'name'        => 'SphinxSearch',
    'author'      => 'Svemir Brkic, Paul Grinberg',
    'email'       => 'svemir at thirdblessing dot net, gri6507 at yahoo dot com',
    'url'         => 'http://www.mediawiki.org/wiki/Extension:SphinxSearch',
    'description' => 'Replace MediaWiki search engine with [http://www.sphinxsearch.com/ Sphinx].'
);

# this assumes you have copied sphinxapi.php from your Sphinx
# installation folder to your SphinxSearch extension folder
require_once ( dirname( __FILE__ ) . "/sphinxapi.php" );

# Host and port on which searchd deamon is tunning
#if (!defined('$wgSphinxSearch_host')) $wgSphinxSearch_host = 'localhost';
#if (!defined('$wgSphinxSearch_port')) $wgSphinxSearch_port = 3312;
# Main sphinx.conf index to search
#if (!defined('$wgSphinxSearch_index')) $wgSphinxSearch_index = "wiki_main";

# Default Sphinx search mode
$wgSphinxSearch_mode = SPH_MATCH_EXTENDED;

# By default, search will return articles that match any of the words in the search
# To change that to require all words to match by default, uncomment the next line
#$wgSphinxMatchAll = 1;

# Number of matches to display at once
$wgSphinxSearch_matches = 10;

# Weights of individual indexed columns. This gives page titles extra weight
$wgSphinxSearch_weights = array('old_text'=>1, 'page_title'=>100);

# If you want to enable hierarchical category search, specify the top category of your hierarchy here
#$wgSphinxTopSearchableCategory = 'Subject_areas';

# If you want sub-categories to be fetched as parent categories are checked,
# also set $wgUseAjax to true in your LocalSettings file, so that the following can be used:
#$wgAjaxExportList[] = 'SphinxSearch::ajaxGetCategoryChildren';

# Web-accessible path to the extension's folder
$wgSphinxSearchExtPath = '/extensions/SphinxSearch';

##########################################################
# Use Aspell to suggest possible misspellings. This could be provided via either
# PHP pspell module (http://www.php.net/manual/en/ref.pspell.php) or command line
# insterface to ASpell

# Should the suggestion mode be enabled?
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

if ( !function_exists( 'extAddSpecialPage' ) ) {
    # Download from http://svn.wikimedia.org/svnroot/mediawiki/trunk/extensions/ExtensionFunctions.php
    require_once( dirname(__FILE__) . '/ExtensionFunctions.php' );
}

extAddSpecialPage( dirname(__FILE__) . '/SphinxSearch_body.php', ($wgDisableInternalSearch ? 'Search' : 'SphinxSearch'), 'SphinxSearch' );

if ($wgSphinxSuggestMode) {
    require_once(dirname(__FILE__) . '/SphinxSearch_spell.php');
}

if ($wgSphinxSuggestMode && $wgSphinxSearchPersonalDictionary) {
   extAddSpecialPage(dirname(__FILE__) . '/SphinxSearch_PersonalDict.php', 'SphinxSearchPersonalDict', 'SphinxSearchPersonalDict');
}

?>
