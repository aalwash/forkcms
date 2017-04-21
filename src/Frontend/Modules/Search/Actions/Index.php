<?php

namespace Frontend\Modules\Search\Actions;

/*
 * This file is part of Fork CMS.
 *
 * For the full copyright and license information, please view the license
 * file that was distributed with this source code.
 */

use Symfony\Component\Filesystem\Filesystem;
use Frontend\Core\Engine\Base\Block as FrontendBaseBlock;
use Frontend\Core\Engine\Form as FrontendForm;
use Frontend\Core\Language\Language as FL;
use Frontend\Core\Engine\Model as FrontendModel;
use Frontend\Core\Engine\Navigation as FrontendNavigation;
use Frontend\Modules\Search\Engine\Model as FrontendSearchModel;

/**
 * This action will display a form to search
 */
class Index extends FrontendBaseBlock
{
    /**
     * The form instance
     *
     * @var FrontendForm
     */
    protected $form;

    /**
     * Name of the cache file
     *
     * @var string
     */
    private $cacheFile;

    /**
     * The items
     *
     * @var array
     */
    private $items;

    /**
     * Limit of data to fetch
     *
     * @var int
     */
    private $limit;

    /**
     * Offset of data to fetch
     *
     * @var int
     */
    private $offset;

    /**
     * The pagination array
     * It will hold all needed parameters, some of them need initialization.
     *
     * @var array
     */
    protected $pagination = array(
        'limit' => 20,
        'offset' => 0,
        'requested_page' => 1,
        'num_items' => null,
        'num_pages' => null,
    );

    /**
     * The requested page
     *
     * @var int
     */
    private $requestedPage;

    /**
     * The search term
     *
     * @var string
     */
    private $term = '';

    /**
     * Display
     */
    private function display()
    {
        // set variables
        $this->requestedPage = $this->URL->getParameter('page', 'int', 1);
        $this->limit = $this->get('fork.settings')->get('Search', 'overview_num_items', 20);
        $this->offset = ($this->requestedPage * $this->limit) - $this->limit;
        $this->cacheFile = FRONTEND_CACHE_PATH . '/' . $this->getModule() . '/' .
                           LANGUAGE . '_' . md5($this->term) . '_' .
                           $this->offset . '_' . $this->limit . '.php';

        // load the cached data
        if (!$this->getCachedData()) {
            // ... or load the real data
            $this->getRealData();
        }

        // parse
        $this->parse();
    }

    /**
     * Execute the extra
     */
    public function execute()
    {
        parent::execute();
        $this->loadTemplate();
        $this->loadForm();
        $this->validateForm();
        $this->display();
        $this->saveStatistics();
    }

    /**
     * Load the cached data
     *
     * @return bool
     */
    private function getCachedData(): bool
    {
        // no search term = no search
        if (!$this->term) {
            return false;
        }

        // debug mode = no cache
        if ($this->getContainer()->getParameter('kernel.debug')) {
            return false;
        }

        // check if cache file exists
        if (!is_file($this->cacheFile)) {
            return false;
        }

        // get cache file modification time
        $cacheInfo = @filemtime($this->cacheFile);

        // check if cache file is recent enough (1 hour)
        if (!$cacheInfo || $cacheInfo < strtotime('-1 hour')) {
            return false;
        }

        // include cache file
        require_once $this->cacheFile;

        // set info (received from cache)
        $this->pagination = $pagination;
        $this->items = $items;

        return true;
    }

    /**
     * Load the data
     */
    private function getRealData()
    {
        // no search term = no search
        if (!$this->term) {
            return;
        }

        // set url
        $this->pagination['url'] = FrontendNavigation::getURLForBlock('Search') . '?form=search&q=' . $this->term;

        // populate calculated fields in pagination
        $this->pagination['limit'] = $this->limit;
        $this->pagination['offset'] = $this->offset;
        $this->pagination['requested_page'] = $this->requestedPage;

        // get items
        $this->items = FrontendSearchModel::search(
            $this->term,
            $this->pagination['limit'],
            $this->pagination['offset']
        );

        // populate count fields in pagination
        // this is done after actual search because some items might be
        // activated/deactivated (getTotal only does rough checking)
        $this->pagination['num_items'] = FrontendSearchModel::getTotal($this->term);
        $this->pagination['num_pages'] = (int) ceil($this->pagination['num_items'] / $this->pagination['limit']);

        // num pages is always equal to at least 1
        if ($this->pagination['num_pages'] === 0) {
            $this->pagination['num_pages'] = 1;
        }

        // redirect if the request page doesn't exist
        if ($this->requestedPage < 1 || $this->requestedPage > $this->pagination['num_pages']) {
            $this->redirect(FrontendNavigation::getURL(404));
        }

        // debug mode = no cache
        if ($this->getContainer()->getParameter('kernel.debug')) {
            return;
        }

        // set cache content
        $filesystem = new Filesystem();
        $filesystem->dumpFile(
            $this->cacheFile,
            "<?php\n" . '$pagination = ' . var_export($this->pagination, true) . ";\n" . '$items = ' . var_export(
                $this->items,
                true
            ) . ";\n?>"
        );
    }

    /**
     * Load the form
     */
    private function loadForm()
    {
        // create form
        $this->form = new FrontendForm('search', null, 'get', null, false);

        // could also have been submitted by our widget
        if (!\SpoonFilter::getGetValue('q', null, '')) {
            $_GET['q'] = \SpoonFilter::getGetValue('q_widget', null, '');
        }

        // create elements
        $this->form->addText('q')->setAttributes(
            array(
                'data-role' => 'fork-search-field',
                'data-autocomplete' => 'enabled',
                'data-live-suggest' => 'enabled',
            )
        );

        // since we know the term just here we should set the canonical url here
        $canonicalUrl = SITE_URL . FrontendNavigation::getURLForBlock('Search');
        if (isset($_GET['q']) && $_GET['q'] !== '') {
            $canonicalUrl .= '?q=' . \SpoonFilter::htmlspecialchars($_GET['q']);
        }
        $this->header->setCanonicalUrl($canonicalUrl);
    }

    /**
     * Parse the data into the template
     */
    private function parse()
    {
        $this->addJS('/js/vendors/typeahead.bundle.min.js', true, false);
        $this->addCSS('Search.css');

        // parse the form
        $this->form->parse($this->tpl);

        // no search term = no search
        if (!$this->term) {
            return;
        }

        // assign articles
        $this->tpl->assign('searchResults', $this->items);
        $this->tpl->assign('searchTerm', $this->term);

        // parse the pagination
        $this->parsePagination();
    }

    /**
     * Save statistics
     */
    private function saveStatistics()
    {
        // no search term = no search
        if (!$this->term) {
            return;
        }

        // previous search result
        $previousTerm = \SpoonSession::exists('searchTerm') ? \SpoonSession::get('searchTerm') : '';
        \SpoonSession::set('searchTerm', '');

        // save this term?
        if ($previousTerm !== $this->term) {
            FrontendSearchModel::save(
                [
                    'term' => $this->term,
                    'language' => LANGUAGE,
                    'time' => FrontendModel::getUTCDate(),
                    'data' => serialize(['server' => $_SERVER]),
                    'num_results' => $this->pagination['num_items'],
                ]
            );
        }

        // save current search term in cookie
        \SpoonSession::set('searchTerm', $this->term);
    }

    /**
     * Validate the form
     */
    private function validateForm()
    {
        if (!$this->form->isSubmitted()) {
            return;
        }

        // cleanup the submitted fields, ignore fields that were added by hackers
        $this->form->cleanupFields();

        // validate required fields
        $this->form->getField('q')->isFilled(FL::err('TermIsRequired'));

        // no errors?
        if ($this->form->isCorrect()) {
            // get search term
            $this->term = $this->form->getField('q')->getValue();
        }
    }
}
