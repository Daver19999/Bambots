<?php
/**
 Copyright 2013 Myers Enterprises II

 Licensed under the Apache License, Version 2.0 (the "License");
 you may not use this file except in compliance with the License.
 You may obtain a copy of the License at

 http://www.apache.org/licenses/LICENSE-2.0

 Unless required by applicable law or agreed to in writing, software
 distributed under the License is distributed on an "AS IS" BASIS,
 WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 See the License for the specific language governing permissions and
 limitations under the License.
 */

namespace com_brucemyers\MediaWiki;

use com_brucemyers\Util\Config;
use Exception;

class EmbeddedInLister
{
    protected $mediawiki;
    protected $params;
    protected $continue = array('continue' => '');

    /**
     * Constructor
     *
     * @param $mediawiki MediaWiki
     * @param $title string Page title that is embedded, include namespace prefix
     * @param $namespace string (optional) default = 0, separate multiple with '|'
     */
    public function __construct($mediawiki, $title, $namespace = '0')
    {
        $this->mediawiki = $mediawiki;
        $this->params = array(
            'eititle' => $title,
            'eilimit' => Config::get(MediaWiki::WIKICHANGESINCREMENT),
            'einamespace' => $namespace
        );
    }

    /**
     * Get next batch of new pages
     *
     * @return mixed false: no more pages, array: keys = pageid, ns, title
     */
    public function getNextBatch()
    {
        if ($this->continue === false) return false;
        $params = array_merge($this->params, $this->continue);

        $ret = $this->mediawiki->getList('embeddedin', $params);

        if (isset($ret['error'])) throw new Exception('EmbeddedInLister.getNextBatch() failed ' . $ret['error']);
        if (isset($ret['continue'])) $this->continue = $ret['continue'];
        else $this->continue = false;

        return $ret['query']['embeddedin'];
    }
}