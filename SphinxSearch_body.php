<?php

/**
 * SphinxSearch extension code for MediaWiki
 *
 * http://www.mediawiki.org/wiki/Extension:SphinxSearch
 *
 * Developed by Paul Grinberg and Svemir Brkic
 *
 * Released under GNU General Public License (see http://www.fsf.org/licenses/gpl.html)
 *
 */

global $IP;
require_once($IP.'/includes/SpecialPage.php');
require_once($IP.'/includes/SearchEngine.php');

function efSphinxSearchGetSearchableCategories($categories)
{
    $dbr = &wfGetDB(DB_SLAVE);
    extract($dbr->tableNames('categorylinks'));

    $cats=array();
    $sql = <<<EOT
SELECT CONCAT('"',cl_to,'"') as title, COUNT(*) as articles_count
  FROM $categorylinks
  GROUP BY cl_to
  HAVING articles_count >= 10
  ORDER BY articles_count DESC
  LIMIT 10
EOT;
    $res = $dbr->query($sql);
    $count = $dbr->numRows($res);
    for ($i = 0; $i < $count; $i++)
    {
        $obj = $dbr->fetchObject($res);
        array_push($cats, $obj->title);
    }
    array_push($cats, '""');
    $cats_in = implode(",",$cats);

    $sql = <<<EOT
SELECT page_id, page_title FROM page
WHERE
    page_title IN ($cats_in)
    AND page_namespace=14
EOT;
    $res = $dbr->query($sql);
    $count = $dbr->numRows($res);
    for ($i = 0; $i < $count; $i++)
    {
        $obj = $dbr->fetchObject($res);
        $categories[$obj->page_id]=$obj->page_title;
    }
    return true;
}

global $wgHooks;
$wgHooks['SphinxSearchGetSearchableCategories'][] = 'efSphinxSearchGetSearchableCategories';

class SphinxSearch extends SpecialPage
{
    function SphinxSearch()
    {
        global $wgDisableInternalSearch;
        if ($wgDisableInternalSearch)
            SpecialPage::SpecialPage("Search");
        else
            SpecialPage::SpecialPage("SphinxSearch");
        self::loadMessages();
        return true;
    }

    function loadMessages()
    {
        static $messagesLoaded = false;
        global $wgMessageCache;
        if ($messagesLoaded)
            return;
        $messagesLoaded = true;

        $allMessages = array(
            'en' => array(
                'sphinxsearch'             => 'Search Wiki Using Sphinx',
                'sphinxSearchInNamespaces' => '<p>Search in namespaces:<br>',
                'sphinxSearchInCategories' => '<p>Search in categories:<br>',
                'sphinxResultPage'         => 'Result Page:&nbsp;&nbsp;',
                'sphinxPreviousPage'       => 'Previous',
                'sphinxNextPage'           => 'Next',
                'sphinxSearchPreamble'     => "Displaying %d-%d of %d matches for query '''%s''' retrieved in %0.3f sec with following stats:",
                'sphinxSearchStats'        => "* '''%s''' found %d times in %d documents",
                'sphinxSearchButton'       => 'Search',
                'sphinxSearchEpilogue'     => 'Additional database time was %0.3f sec.',
                'sphinxSearchDidYouMean'   => 'Did you mean',
                'sphinxMatchAny'           => 'match any word',
                'sphinxMatchAll'           => 'match all words',
                'sphinxLoading'            => 'Loading...'
            )
        );

        foreach ($allMessages as $lang => $langMessages)
            $wgMessageCache->addMessages( $langMessages, $lang );
        return true;
    }

    function searchableNamespaces()
    {
        $namespaces = SearchEngine::searchableNamespaces();
        wfRunHooks('SphinxSearchFilterSearchableNamespaces', array(&$namespaces));
        return $namespaces;
    }

    function searchableCategories()
    {
        global $wgSphinxTopSearchableCategory;
        if ($wgSphinxTopSearchableCategory)
            $categories = self::getChildrenCategories($wgSphinxTopSearchableCategory);
        else
            $categories = array();
        wfRunHooks('SphinxSearchGetSearchableCategories', array(&$categories));
        return $categories;
    }

    function getChildrenCategories($parent)
    {
        global $wgMemc, $wgDBname;

        $categories = null;
        if (is_object($wgMemc))
        {
            $cache_key = $wgDBname.':sphinx_cats:'.md5($parent);
            $categories = $wgMemc->get( $cache_key );
        }
        if (!is_array($categories))
        {
            $categories = array();
            $dbr =& wfGetDB( DB_SLAVE );
            $res = $dbr->select(
                array('categorylinks', 'page'),
                array('cl_from', 'cl_sortkey', 'page_title'),
                array('1',
                      'cl_from  =  page_id',
                      'cl_to'   => $parent,
                      'page_namespace' => NS_CATEGORY),
                __METHOD__,
                array('ORDER BY' => 'cl_sortkey')
            );
            while ($x = $dbr->fetchObject($res))
                $categories[$x->cl_from] = $x->cl_sortkey;
            if ($cache_key)
                $wgMemc->set($cache_key, $categories, 86400);
            $dbr->freeResult($res);
        }
        return $categories;
    }

    function ajaxGetCategoryChildren($parent_id)
    {
        $title = Title::newFromID( $parent_id );
        if (!$title)
            return false;

        # Retrieve page_touched for the category
        $dbkey = $title->getDBkey();
        $dbr =& wfGetDB(DB_SLAVE);
        $touched = $dbr->selectField(
            'page', 'page_touched',
            array('page_namespace' => NS_CATEGORY,'page_title' => $dbkey),
            __METHOD__
        );

        $response = new AjaxResponse();
        if ($response->checkLastModified($touched))
            return $response;

        $categories = self::getChildrenCategories($dbkey);
        $html = self::getCategoryCheckboxes($categories, array(), $parent_id);
        $response->addText($html);

        return $response;
    }

    function execute($par)
    {
        global $wgRequest, $wgOut, $wgUser, $wgSphinxMatchAll;

        # extract the options from the GET query
        $SearchWord = $wgRequest->getText('search', $par);
        if (!$SearchWord)
            $SearchWord = $wgRequest->getText('sphinxsearch', $par);
        # see if we want to go the title directly
        # this logic is actually reversed (if we are not doing a search,
        # thn try to go to title directly). This is needed because IE has a
        # different behavior when the <ENTER> button is pressed in a form -
        # it does not send the name of the default button!
        if (!$wgRequest->getVal('fulltext'))
            $this->goResult($SearchWord);

        $this->setHeaders();
        $wgOut->setPagetitle(wfMsg('sphinxsearch'));

        $namespaces = array();
        $all_namespaces = self::searchableNamespaces();
        foreach($all_namespaces as $ns => $name)
            if ($wgRequest->getCheck("ns{$ns}"))
                $namespaces[] = $ns;
        if (!count($namespaces))
            foreach($all_namespaces as $ns => $name)
                if ($wgUser->getOption('searchNs' . $ns))
                    $namespaces[] = $ns;

        $categories = $wgRequest->getIntArray("cat", array());

        $page = $wgRequest->getInt('page', 1);
        $wgSphinxMatchAll = $wgRequest->getInt('match_all', intval($wgSphinxMatchAll));

        # do the actual search
        $found = self::wfSphinxSearch($SearchWord, $namespaces, $categories, $page);

        # prepare for the next search
        if ($found)
            self::createNextPageBar($page, $found, $SearchWord, $namespaces, $categories);

        self::createNewSearchForm($SearchWord, $namespaces, $categories);
    }

    function goResult($term)
    {
        global $wgOut, $wgGoToEdit;

        # Try to go to page as entered.
        $t = Title::newFromText( $term );

        # If the string cannot be used to create a title
        if (is_null($t))
            return;

        # If there's an exact or very near match, jump right there.
        $t = SearchEngine::getNearMatch($term);
        wfRunHooks('SphinxSearchGetNearMatch', array(&$term, &$t));
        if(!is_null($t))
        {
            $wgOut->redirect( $t->getFullURL() );
            return;
        }

        # No match, generate an edit URL
        $t = Title::newFromText( $term );
        if (!is_null($t))
        {
            # If the feature is enabled, go straight to the edit page
            if ($wgGoToEdit)
            {
                $wgOut->redirect($t->getFullURL('action=edit'));
                return;
            }
        }

        $wgOut->addWikiText(wfMsg('noexactmatch', wfEscapeWikiText($term)));
    }

    /**
     * Search for "$term" $namespaces. If $namespaces is emtpy search all namespaces.
     * Display the results of the search one page at a time, displaying $page.
     * Returns the number of matches.
     */
    function wfSphinxSearch($term, $namespaces = array(), $categories = array(), $page = 1)
    {
        global $wgOut;
        global $wgSphinxSearch_host,  $wgSphinxSearch_port;
        global $wgSphinxSearch_index, $wgSphinxSearch_matches, $wgSphinxSearch_mode, $wgSphinxSearch_weights;
        global $wgSphinxSuggestMode,  $wgSphinxMatchAll, $wgSphinxSearch_maxmatches, $wgSphinxSearch_cutoff;

        $found = 0;

        # don't do anything for blank searches
        if (!preg_match('/[\w\pL\d]/u', $term))
            return $found;

        wfRunHooks('SphinxSearchBeforeResults', array($term, $page));

        if ($wgSphinxSearch_mode == SPH_MATCH_EXTENDED && $wgSphinxMatchAll != '1')
        {
            # make OR the default in extended mode
            $search_term = preg_replace('/[\s_\-]+/', '|', trim($term));
        }
        else
            $search_term = $term;

        $cl = new SphinxClient();

        # setup the options for searching
        if (isset($wgSphinxSearch_host) && isset($wgSphinxSearch_port))
            $cl->SetServer($wgSphinxSearch_host, $wgSphinxSearch_port);
        if (count($wgSphinxSearch_weights))
        {
            if (is_string(key($wgSphinxSearch_weights)))
                $cl->SetFieldWeights($wgSphinxSearch_weights);
            else
                $cl->SetWeights($wgSphinxSearch_weights);
        }
        if (isset($wgSphinxSearch_mode))
            $cl->SetMatchMode($wgSphinxSearch_mode);
        if (count($namespaces))
            $cl->SetFilter('page_namespace', $namespaces);
        if (count($categories))
            $cl->SetFilter('category', $categories);
        if (isset($wgSphinxSearch_groupby) && isset($wgSphinxSearch_groupsort))
            $cl->SetGroupBy($wgSphinxSearch_groupby, SPH_GROUPBY_ATTR, $wgSphinxSearch_groupsort);
        if (isset($wgSphinxSearch_sortby))
            $cl->SetSortMode(SPH_SORT_EXTENDED, $wgSphinxSearch_sortby);
        $cl->SetLimits (($page-1)*$wgSphinxSearch_matches, $wgSphinxSearch_matches, $wgSphinxSearch_maxmatches, $wgSphinxSearch_cutoff);

        # search all indices
        $res = $cl->Query($search_term, "*");

        # display the results
        if (!$res)
            $wgOut->addWikiText("Query failed: " . $cl->GetLastError() . ".\n");
        else
        {
            if ($cl->GetLastWarning())
                $wgOut->addWikiText("WARNING: " . $cl->GetLastWarning() . "\n\n");
            $found = $res['total_found'];

            if ($wgSphinxSuggestMode) {
                $sc = new SphinxSearch_spell;
                $didyoumean = $sc->spell($search_term);
                if ($didyoumean) {
                    $wgOut->addhtml(
                        wfMsg('sphinxSearchDidYouMean') .
                        " <b><a href='" . $this->getActionURL($didyoumean, $namespaces, $categories) . "1'>" .
                        $didyoumean . '</a></b>?');
                }
            }

            $preamble = sprintf(wfMsg('sphinxSearchPreamble'),
                (($page-1)*$wgSphinxSearch_matches+1 > $res['total']) ? $res['total'] : ($page-1)*$wgSphinxSearch_matches+1,
                ($page*$wgSphinxSearch_matches > $res['total']) ? $res['total'] :  $page*$wgSphinxSearch_matches,
                $res['total'],
                $term,
                $res['time']
            );
            $wgOut->addWikiText($preamble);
            if (is_array($res["words"]))
            {
                foreach ($res["words"] as $word => $info)
                    $wgOut->addWikiText(
                        sprintf(wfMsg('sphinxSearchStats'),
                        $word,
                        $info['hits'],
                        $info['docs'])
                    );
            }
            $wgOut->addWikiText("\n");
            $start_time = microtime(true);

            if (isset($res["matches"]) && is_array($res["matches"]))
            {
                $wgOut->addWikiText("----");
                $dbr = wfGetDB(DB_SLAVE);
                $excerpts_opt = array(
                    "before_match"    => "<span style='color:red'>",
                    "after_match"     => "</span>",
                    "chunk_separator" => " ... ",
                    "limit"           => 400,
                    "around"          => 15
                );

                foreach ($res["matches"] as $doc => $docinfo)
                {
                    $sql = "SELECT old_text FROM ".$dbr->tableName('text')." WHERE old_id=".$docinfo['attrs']['old_id'];
                    $res = $dbr->query($sql, __METHOD__);
                    if ($dbr->numRows($res))
                    {
                        $row = $dbr->fetchRow($res);
                        $title_obj = Title::newFromID( $doc );
                        if (is_object($title_obj))
                        {
                            $wiki_title = $title_obj->getPrefixedText();
                            $wiki_path = $title_obj->getPrefixedDBkey();
                            $wgOut->addWikiText("* <span style='font-size:110%;'>[[:$wiki_path|$wiki_title]]</span>");
                            # uncomment this line to see the weights etc. as HTML comments in the source of the page
                            $wgOut->addHTML("<!-- page_id: ".$doc."\ninfo: ".print_r($docinfo, true)." -->");
                            $excerpts = $cl->BuildExcerpts(array($row[0]), $wgSphinxSearch_index, $term, $excerpts_opt);
                            if (!is_array($excerpts))
                                $excerpts = array("ERROR: " . $cl->GetLastError());
                            foreach ($excerpts as $entry)
                            {
                                # add excerpt to output, removing some wiki markup, and breaking apart long strings
                                $entry = preg_replace('/([\[\]\{\}\*\#\|\!]+|==+)/', ' ', strip_tags($entry, '<span><br>'));
                                $entry = join('<br>', preg_split('/(\S{60})/', $entry, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE));
                                $wgOut->addHTML("<div style='margin: 0.2em 1em 1em 1em;'>$entry</div>\n");
                            }
                        }
                    }
                    $dbr->freeResult($res);
                }
                $wgOut->addWikiText(sprintf(wfMsg('sphinxSearchEpilogue'), microtime(true) - $start_time));
            }
        }

        wfRunHooks('SphinxSearchAfterResults', array($term, $page));

        return $found;
    }

    function getActionURL($term, $namespaces, $categories)
    {
        global $wgDisableInternalSearch, $wgSphinxMatchAll;

        $titleObj = SpecialPage::getTitleFor("SphinxSearch");
        $kiaction = $titleObj->getLocalUrl();
        $searchField = ($wgDisableInternalSearch ? 'search' : 'sphinxsearch');
        $term = urlencode($term);
        $qry = $kiaction . "?$searchField={$term}&amp;fulltext=".wfMsg('sphinxSearchButton')."&amp;";
        $qry .= "match_all=".($wgSphinxMatchAll ? 1 : 0)."&amp;";
        foreach ($namespaces as $ns)
            $qry .= "ns{$ns}=1&amp;";
        foreach ($categories as $cat)
            $qry .= "cat%5B%5D=$cat&amp;";
        $qry .= "page=";

        return $qry;
    }

    function createNextPageBar($page, $found, $term, $namespaces, $categories)
    {
        global $wgOut, $wgSphinxSearch_matches;

        $qry = $this->getActionURL($term, $namespaces, $categories);

        $display_pages = 10;
        $max_page = ceil($found / $wgSphinxSearch_matches);
        $center_page = floor(($page + $display_pages) / 2);
        $first_page = $center_page - $display_pages / 2;
        if ($first_page < 1)
            $first_page = 1;
        $last_page = $first_page + $display_pages - 1;
        if ($last_page > $max_page)
            $last_page = $max_page;
        if ($first_page != $last_page)
        {
            $wgOut->addWikiText("----");
            $wgOut->addHTML("<center><table border='0' cellpadding='0' width='1%' cellspacing='0'><tr align='center' valign='top'><td valign='bottom' nowrap='1'>" . wfMsg('sphinxResultPage') . "</td>");

            if ($first_page > 1)
            {
                $prev_page  = "<td>&nbsp;<a href='{$qry}";
                $prev_page .= $page-1 . "'>" . wfMsg('sphinxPreviousPage') ."</a>&nbsp;</td>";
                $wgOut->addHTML($prev_page);
            }
            for ($i = $first_page; $i < $page; $i++)
                $wgOut->addHTML("<td>&nbsp;<a href='{$qry}{$i}'>{$i}</a>&nbsp;</td>");
            $wgOut->addHTML("<td>&nbsp;<b>{$page}</b>&nbsp;</td>");
            for ($i = $page+1; $i <= $last_page; $i++)
                $wgOut->addHTML("<td>&nbsp;<a href='{$qry}{$i}'>{$i}</a>&nbsp;</td>");
            if ($last_page < $max_page)
            {
                $next_page  = "<td>&nbsp;<a href='{$qry}";
                $next_page .= $page+1 . "'>" . wfMsg('sphinxNextPage') ."</a>&nbsp;</td>";
                $wgOut->addHTML($next_page);
            }

            $wgOut->addHTML("</tr></table></center>");
        }
    }

    function createNewSearchForm($SearchWord, $namespaces, $categories)
    {
        global $wgOut, $wgDisableInternalSearch, $wgSphinxSearch_mode, $wgSphinxMatchAll;
        global $wgUseAjax, $wgJsMimeType, $wgScriptPath, $wgSphinxSearchExtPath, $wgSphinxSearchJSPath, $wgRequest;

        $titleObj = SpecialPage::getTitleFor( "SphinxSearch" );
        $kiAction = $titleObj->getLocalUrl();
        $searchField = ($wgDisableInternalSearch ? 'search' : 'sphinxsearch');
        $wgOut->addHTML("<form action='$kiAction' method='GET'>
            <input type='hidden' name='title' value='".self::esc($titleObj)."'>
            <input type='text' name='$searchField' maxlength='100' value='".self::esc($SearchWord)."'>
            <input type='submit' name='fulltext' value='".self::esc(wfMsg('sphinxSearchButton'))."'>");

        if ($wgSphinxSearch_mode == SPH_MATCH_EXTENDED)
        {
            $wgOut->addHTML("<div style='margin:0.5em 0 0.5em 0;'><input type='radio' name='match_all' value='0' ".
                ($wgSphinxMatchAll ? "" : "checked='checked'")." />".
                wfMsg('sphinxMatchAny')." <input type='radio' name='match_all' value='1' ".
                ($wgSphinxMatchAll ? "checked='checked'" : "")." />".
                wfMsg('sphinxMatchAll')."</div>");
        }

        # get user settings for which namespaces to search
        $wgOut->addHTML("<div style='width:30%; border:1px #eee solid; padding:4px; margin-right:1px; float:left;'>");
        $wgOut->addHTML(wfMsg('sphinxSearchInNamespaces'));
        $all_namespaces = self::searchableNamespaces();
        foreach($all_namespaces as $ns => $name)
        {
            $checked = in_array($ns, $namespaces) ? ' checked="checked"' : '';
            $name = str_replace('_', ' ', $name);
            if (!$name)
                $name = wfMsg('blanknamespace');
            $wgOut->addHTML("<label><input type='checkbox' value='1' name='ns$ns'$checked />$name</label><br/>");
        }

        $all_categories = self::searchableCategories();
        if (is_array($all_categories) && count($all_categories))
        {
            $cat_parents = $wgRequest->getIntArray("cat_parents", array());
            $wgOut->addScript(Skin::makeVariablesScript(array(
                'sphinxLoadingMsg'      => wfMsg('sphinxLoading'),
                'wgSphinxSearchExtPath' => ($wgSphinxSearchJSPath ? $wgSphinxSearchJSPath : $wgSphinxSearchExtPath)
            )));
            $wgOut->addScript(
                "<script type='{$wgJsMimeType}' src='".($wgSphinxSearchJSPath ? $wgSphinxSearchJSPath : $wgSphinxSearchExtPath)."/SphinxSearch.js'></script>\n"
            );
            $wgOut->addHTML("</div><div style='width:30%; border:1px #eee solid; padding:4px; margin-right:1px; float:left;'>");
            $wgOut->addHTML(wfMsg('sphinxSearchInCategories'));
            $wgOut->addHTML(self::getCategoryCheckboxes($all_categories, $categories, '', $cat_parents));
        }
        $wgOut->addHTML("</div></form><br clear='both'>");

        # Put a Sphinx label for this search
        $wgOut->addHTML("<div style='text-align:center'>Powered by <a href='http://www.sphinxsearch.com/'>Sphinx</a></div>");
    }

    function getCategoryCheckboxes($all_categories, $selected_categories = array(), $parent_id='', $cat_parents = array())
    {
        global $wgUseAjax, $wgRequest;

        $html = '';

        foreach($all_categories as $cat => $name)
        {
            $input_attrs = '';
            if (in_array($cat, $selected_categories))
                $input_attrs .= ' checked="checked"';
            $name = str_replace('_', ' ', $name);
            if(!$name)
                $name = wfMsg('blanknamespace');
            if (isset($cat_parents['_'.$cat]) && ($input_attrs || $cat_parents['_'.$cat] > 0))
            {
                $title = Title::newFromID( $cat );
                $children = self::getCategoryCheckboxes(self::getChildrenCategories($title->getDBkey()), $selected_categories, $cat, $cat_parents);
            }
            else
                $children = '';
            if ($wgUseAjax)
                $input_attrs .= " onmouseup='sphinxShowCats(this)'";
            $html .= "<label><input type='checkbox' id='{$parent_id}_$cat' value='$cat' name='cat[]'$input_attrs />$name</label><div id='cat{$cat}_children'>$children</div>\n";
        }
        if ($parent_id && $html)
            $html = "<input type='hidden' name='cat_parents[_$parent_id]' value='".intval($cat_parents['_'.$parent_id])."' /><div style='margin-left:10px; margin-bottom:4px; padding-left:8px; border-left:1px dashed #ccc; border-bottom:1px solid #ccc;'>".$html."</div>";
        return $html;
    }

    function esc($s)
    {
        return htmlspecialchars($s, ENT_QUOTES);
    }
}

?>
