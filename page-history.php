<?php

namespace Grav\Plugin;

use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Page\Page;
use Grav\Common\Plugin;
use Grav\Common\Uri;
use Grav\Plugin\PageHistory\GitLogReader;
use Twig\TwigFunction;

require_once __DIR__ . '/classes/GitLogReader.php';

class PageHistoryPlugin extends Plugin
{
    /** @var string route prefix from config, normalised: leading slash, no trailing slash */
    protected $prefix = '/history';

    /** @var string|null repo-relative page route requested, '' for site-wide, null when not a history route */
    protected $tail = null;

    /** @var GitLogReader|null */
    protected $reader = null;

    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
        ];
    }

    public function onPluginsInitialized(): void
    {
        if ($this->isAdmin()) {
            return;
        }

        $this->enable([
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
            'onTwigInitialized' => ['onTwigInitialized', 0],
        ]);

        $this->prefix = '/' . trim((string) $this->config->get('plugins.page-history.route_prefix', '/history'), '/');

        /** @var Uri $uri */
        $uri = $this->grav['uri'];
        $route = rtrim($uri->route(), '/');

        if ($route === $this->prefix) {
            $this->tail = '';
        } elseif (str_starts_with($route, $this->prefix . '/')) {
            $this->tail = substr($route, strlen($this->prefix) + 1);
        } else {
            return;
        }

        $this->enable([
            'onPageInitialized' => ['onPageInitialized', 0],
        ]);
    }

    public function onTwigTemplatePaths(): void
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    public function onTwigInitialized(): void
    {
        $this->grav['twig']->twig()->addFunction(
            new TwigFunction('page_history', [$this, 'renderPageHistory'], ['is_safe' => ['html']])
        );
    }

    public function onPageInitialized(): void
    {
        if ($this->tail === '') {
            $this->renderRecentChanges();

            return;
        }

        /** @var \Grav\Common\Page\Pages $pages */
        $pages = $this->grav['pages'];
        $target = $pages->find('/' . $this->tail);

        if ($target === null || !$target->routable() || !$this->pageHistoryAllowed($target)) {
            // Leave the original (non-routable, for this made-up route) page in
            // place so Grav's normal 404 handling in PagesProcessor takes over.
            return;
        }

        $this->renderPageHistoryRoute($target);
    }

    /**
     * Twig function: {{ page_history(page) }} — inline "View history" widget
     * for a theme template to place explicitly. Returns '' when history isn't
     * available or isn't allowed for this page.
     */
    public function renderPageHistory($page = null, array $options = []): string
    {
        $page = $page instanceof PageInterface ? $page : ($this->grav['page'] ?? null);
        if (!$page instanceof PageInterface || !$this->pageHistoryAllowed($page)) {
            return '';
        }

        $entries = $this->getReader()->log($page->filePath(), [
            'max_count' => $options['max_count'] ?? $this->config->get('plugins.page-history.pagination_count', 20),
        ]);

        if (empty($entries)) {
            return '';
        }

        return $this->grav['twig']->twig()->render('partials/page-history.html.twig', [
            'history_entries' => $entries,
            'history_page' => $page,
        ]);
    }

    private function renderPageHistoryRoute(PageInterface $target): void
    {
        $entries = $this->getReader()->log($target->filePath(), [
            'max_count' => $this->config->get('plugins.page-history.pagination_count', 20),
        ]);

        $page = new Page();
        $page->init(new \SplFileInfo(__DIR__ . '/pages/history.md'));
        unset($this->grav['page']);
        $this->grav['page'] = $page;

        $twig = $this->grav['twig'];
        $twig->template = 'page-history-route.html.twig';
        $twig->twig_vars['history_prefix'] = $this->prefix;
        $twig->twig_vars['history_page'] = $target;
        $twig->twig_vars['history_entries'] = $entries ?? [];
    }

    private function renderRecentChanges(): void
    {
        $anchor = $this->grav['locator']->findResource('page://');
        $entries = $anchor !== false
            ? $this->getReader()->log($anchor, ['max_count' => $this->config->get('plugins.page-history.pagination_count', 20)])
            : null;

        $page = new Page();
        $page->init(new \SplFileInfo(__DIR__ . '/pages/history.md'));
        unset($this->grav['page']);
        $this->grav['page'] = $page;

        $twig = $this->grav['twig'];
        $twig->template = 'page-history-recent.html.twig';
        $twig->twig_vars['history_available'] = $entries !== null;
        $twig->twig_vars['history_entries'] = $entries ?? [];
    }

    private function pageHistoryAllowed(PageInterface $page): bool
    {
        $header = $page->header();

        if (isset($header->history) && $header->history === false) {
            return false;
        }

        if (!$this->config->get('plugins.page-history.respect_page_access', true)) {
            return true;
        }

        if (isset($header->access)) {
            return false;
        }

        return $page->routable() && $page->published();
    }

    private function getReader(): GitLogReader
    {
        if ($this->reader === null) {
            $this->reader = new GitLogReader([
                'git_dir' => $this->config->get('plugins.page-history.git_dir', ''),
                'work_tree' => $this->config->get('plugins.page-history.work_tree', ''),
                'scope_root' => $this->config->get('plugins.page-history.scope_root', 'pages'),
                'follow_renames' => $this->config->get('plugins.page-history.follow_renames', false),
            ]);
        }

        return $this->reader;
    }
}
