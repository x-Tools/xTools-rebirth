<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\XtoolsHttpException;
use App\Helper\I18nHelper;
use App\Model\EditCounter;
use App\Model\GlobalContribs;
use App\Model\UserRights;
use App\Repository\EditCounterRepository;
use App\Repository\EditRepository;
use App\Repository\GlobalContribsRepository;
use App\Repository\PageRepository;
use App\Repository\ProjectRepository;
use App\Repository\UserRepository;
use App\Repository\UserRightsRepository;
use GuzzleHttp\Client;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Class EditCounterController
 */
class EditCounterController extends XtoolsController
{
    /**
     * Available statistic sections. These can be hand-picked on the index form so that you only get the data you
     * want and hence speed up the tool. Keys are the i18n messages (and DOM IDs), values are the action names.
     */
    private const AVAILABLE_SECTIONS = [
        'general-stats' => 'EditCounterGeneralStats',
        'namespace-totals' => 'EditCounterNamespaceTotals',
        'year-counts' => 'EditCounterYearCounts',
        'month-counts' => 'EditCounterMonthCounts',
        'timecard' => 'EditCounterTimecard',
        'top-edited-pages' => 'TopEditsResultNamespace',
        'rights-changes' => 'EditCounterRightsChanges',
    ];

    protected EditCounter $editCounter;
    protected EditCounterRepository $editCounterRepo;
    protected UserRights $userRights;
    protected UserRightsRepository $userRightsRepo;

    /** @var string[] Which sections to show. */
    protected array $sections;

    /**
     * @inheritDoc
     * @codeCoverageIgnore
     */
    public function getIndexRoute(): string
    {
        return 'EditCounter';
    }

    /**
     * EditCounterController constructor.
     * @param RequestStack $requestStack
     * @param ContainerInterface $container
     * @param CacheItemPoolInterface $cache
     * @param Client $guzzle
     * @param I18nHelper $i18n
     * @param ProjectRepository $projectRepo
     * @param UserRepository $userRepo
     * @param EditCounterRepository $editCounterRepo
     * @param UserRightsRepository $userRightsRepo
     * @param PageRepository $pageRepo
     */
    public function __construct(
        RequestStack $requestStack,
        ContainerInterface $container,
        CacheItemPoolInterface $cache,
        Client $guzzle,
        I18nHelper $i18n,
        ProjectRepository $projectRepo,
        UserRepository $userRepo,
        EditCounterRepository $editCounterRepo,
        UserRightsRepository $userRightsRepo,
        PageRepository $pageRepo
    ) {
        $this->editCounterRepo = $editCounterRepo;
        $this->userRightsRepo = $userRightsRepo;
        parent::__construct($requestStack, $container, $cache, $guzzle, $i18n, $projectRepo, $userRepo, $pageRepo);
    }

    /**
     * Causes the tool to redirect to the Simple Edit Counter if the user has too high of an edit count.
     * @inheritDoc
     * @codeCoverageIgnore
     */
    public function tooHighEditCountRoute(): string
    {
        return 'SimpleEditCounterResult';
    }

    /**
     * @inheritDoc
     * @codeCoverageIgnore
     */
    public function tooHighEditCountActionAllowlist(): array
    {
        return ['rightsChanges'];
    }

    /**
     * @inheritDoc
     * @codeCoverageIgnore
     */
    public function restrictedApiActions(): array
    {
        return ['monthCountsApi', 'timecardApi'];
    }

    /**
     * Every action in this controller (other than 'index') calls this first.
     * If a response is returned, the calling action is expected to return it.
     * @throws AccessDeniedException If attempting to access internal endpoint.
     * @throws XtoolsHttpException If an API request to restricted endpoint when user has not opted in.
     * @codeCoverageIgnore
     */
    protected function setUpEditCounter(): void
    {
        // Whether we're making a subrequest (the view makes a request to another action).
        // Subrequests to the same controller do not re-instantiate a new controller, and hence
        // this flag would not be set in XtoolsController::__construct(), so we must do it here as well.
        $this->isSubRequest = $this->request->get('htmlonly')
            || null !== $this->get('request_stack')->getParentRequest();

        // Return the EditCounter if we already have one.
        if (isset($this->editCounter)) {
            return;
        }

        // Will redirect to Simple Edit Counter if they have too many edits, as defined self::construct.
        $this->validateUser($this->user->getUsername());

        // Store which sections of the Edit Counter they requested.
        $this->sections = $this->getRequestedSections();

        $this->userRights = new UserRights($this->userRightsRepo, $this->project, $this->user, $this->i18n);

        // Instantiate EditCounter.
        $this->editCounter = new EditCounter(
            $this->editCounterRepo,
            $this->i18n,
            $this->userRights,
            $this->project,
            $this->user
        );
    }

    /**
     * The initial GET request that displays the search form.
     * @Route("/ec", name="EditCounter")
     * @Route("/ec/index.php", name="EditCounterIndexPhp")
     * @Route("/ec/{project}", name="EditCounterProject")
     * @return RedirectResponse|Response
     */
    public function indexAction()
    {
        if (isset($this->params['project']) && isset($this->params['username'])) {
            return $this->redirectFromSections();
        }

        $this->sections = $this->getRequestedSections(true);

        // Otherwise fall through.
        return $this->render('editCounter/index.html.twig', [
            'xtPageTitle' => 'tool-editcounter',
            'xtSubtitle' => 'tool-editcounter-desc',
            'xtPage' => 'EditCounter',
            'project' => $this->project,
            'sections' => $this->sections,
            'availableSections' => $this->getSectionNames(),
            'isAllSections' => $this->sections === $this->getSectionNames(),
        ]);
    }

    /**
     * Get the requested sections either from the URL, cookie, or the defaults (all sections).
     * @param bool $useCookies Whether or not to check cookies for the preferred sections.
     *   This option should not be true except on the index form.
     * @return array|mixed|string[]
     * @codeCoverageIgnore
     */
    private function getRequestedSections(bool $useCookies = false)
    {
        // Happens from sub-tool index pages, e.g. see self::generalStatsIndexAction().
        if (isset($this->sections)) {
            return $this->sections;
        }

        // Query param for sections gets priority.
        $sectionsQuery = $this->request->get('sections', '');

        // If not present, try the cookie, and finally the defaults (all sections).
        if ($useCookies && '' == $sectionsQuery) {
            $sectionsQuery = $this->request->cookies->get('XtoolsEditCounterOptions', '');
        }

        // Either a pipe-separated string or an array.
        $sections = is_array($sectionsQuery) ? $sectionsQuery : explode('|', $sectionsQuery);

        // Filter out any invalid section IDs.
        $sections = array_filter($sections, function ($section) {
            return in_array($section, $this->getSectionNames());
        });

        // Fallback for when no valid sections were requested or provided by the cookie.
        if (0 === count($sections)) {
            $sections = $this->getSectionNames();
        }

        return $sections;
    }

    /**
     * Get the names of the available sections.
     * @return string[]
     * @codeCoverageIgnore
     */
    private function getSectionNames(): array
    {
        return array_keys(self::AVAILABLE_SECTIONS);
    }

    /**
     * Redirect to the appropriate action based on what sections are being requested.
     * @return RedirectResponse
     * @codeCoverageIgnore
     */
    private function redirectFromSections(): RedirectResponse
    {
        $this->sections = $this->getRequestedSections();

        if (1 === count($this->sections)) {
            // Redirect to dedicated route.
            $response = $this->redirectToRoute(self::AVAILABLE_SECTIONS[$this->sections[0]], $this->params);
        } elseif ($this->sections === $this->getSectionNames()) {
            $response = $this->redirectToRoute('EditCounterResult', $this->params);
        } else {
            // Add sections to the params, which $this->generalUrl() will append to the URL.
            $this->params['sections'] = implode('|', $this->sections);

            // We want a pretty URL, with pipes | instead of the encoded value %7C
            $url = str_replace('%7C', '|', $this->generateUrl('EditCounterResult', $this->params));

            $response = $this->redirect($url);
        }

        // Save the preferred sections in a cookie.
        $response->headers->setCookie(
            new Cookie('XtoolsEditCounterOptions', implode('|', $this->sections))
        );

        return $response;
    }

    /**
     * Display all results.
     * @Route(
     *     "/ec/{project}/{username}",
     *     name="EditCounterResult",
     *     requirements={
     *         "username" = "(ipr-.+\/\d+[^\/])|([^\/]+)",
     *     }
     * )
     * @return Response|RedirectResponse
     * @codeCoverageIgnore
     */
    public function resultAction()
    {
        $this->setUpEditCounter();

        if (1 === count($this->sections)) {
            // Redirect to dedicated route.
            return $this->redirectToRoute(self::AVAILABLE_SECTIONS[$this->sections[0]], $this->params);
        }

        $ret = [
            'xtTitle' => $this->user->getUsername() . ' - ' . $this->project->getTitle(),
            'xtPage' => 'EditCounter',
            'user' => $this->user,
            'project' => $this->project,
            'ec' => $this->editCounter,
            'sections' => $this->sections,
            'isAllSections' => $this->sections === $this->getSectionNames(),
        ];

        // Used when querying for global rights changes.
        if ($this->getParameter('app.is_wmf')) {
            $ret['metaProject'] = $this->projectRepo->getProject('metawiki');
        }

        return $this->getFormattedResponse('editCounter/result', $ret);
    }

    /**
     * Display the general statistics section.
     * @Route(
     *     "/ec-generalstats/{project}/{username}",
     *     name="EditCounterGeneralStats",
     *     requirements={
     *         "username" = "(ipr-.+\/\d+[^\/])|([^\/]+)",
     *     }
     * )
     * @param GlobalContribsRepository $globalContribsRepo
     * @param EditRepository $editRepo
     * @return Response
     * @codeCoverageIgnore
     */
    public function generalStatsAction(
        GlobalContribsRepository $globalContribsRepo,
        EditRepository $editRepo
    ): Response {
        $this->setUpEditCounter();

        $globalContribs = new GlobalContribs(
            $globalContribsRepo,
            $this->pageRepo,
            $this->userRepo,
            $editRepo,
            $this->user
        );
        $ret = [
            'xtTitle' => $this->user->getUsername(),
            'xtPage' => 'EditCounter',
            'subtool_msg_key' => 'general-stats',
            'is_sub_request' => $this->isSubRequest,
            'user' => $this->user,
            'project' => $this->project,
            'ec' => $this->editCounter,
            'gc' => $globalContribs,
        ];

        // Output the relevant format template.
        return $this->getFormattedResponse('editCounter/general_stats', $ret);
    }

    /**
     * Search form for general stats.
     * @Route(
     *     "/ec-generalstats",
     *     name="EditCounterGeneralStatsIndex",
     *     requirements={
     *         "username" = "(ipr-.+\/\d+[^\/])|([^\/]+)",
     *     }
     * )
     * @return Response
     */
    public function generalStatsIndexAction(): Response
    {
        $this->sections = ['general-stats'];
        return $this->indexAction();
    }

    /**
     * Display the namespace totals section.
     * @Route(
     *     "/ec-namespacetotals/{project}/{username}",
     *     name="EditCounterNamespaceTotals",
     *     requirements={
     *         "username" = "(ipr-.+\/\d+[^\/])|([^\/]+)",
     *     }
     * )
     * @return Response
     * @codeCoverageIgnore
     */
    public function namespaceTotalsAction(): Response
    {
        $this->setUpEditCounter();

        $ret = [
            'xtTitle' => $this->user->getUsername(),
            'xtPage' => 'EditCounter',
            'subtool_msg_key' => 'namespace-totals',
            'is_sub_request' => $this->isSubRequest,
            'user' => $this->user,
            'project' => $this->project,
            'ec' => $this->editCounter,
        ];

        // Output the relevant format template.
        return $this->getFormattedResponse('editCounter/namespace_totals', $ret);
    }

    /**
     * Search form for namespace totals.
     * @Route("/ec-namespacetotals", name="EditCounterNamespaceTotalsIndex")
     * @return Response
     */
    public function namespaceTotalsIndexAction(): Response
    {
        $this->sections = ['namespace-totals'];
        return $this->indexAction();
    }

    /**
     * Display the timecard section.
     * @Route(
     *     "/ec-timecard/{project}/{username}",
     *     name="EditCounterTimecard",
     *     requirements={
     *         "username" = "(ipr-.+\/\d+[^\/])|([^\/]+)",
     *     }
     * )
     * @return Response
     * @codeCoverageIgnore
     */
    public function timecardAction(): Response
    {
        $this->setUpEditCounter();

        $ret = [
            'xtTitle' => $this->user->getUsername(),
            'xtPage' => 'EditCounter',
            'subtool_msg_key' => 'timecard',
            'is_sub_request' => $this->isSubRequest,
            'user' => $this->user,
            'project' => $this->project,
            'ec' => $this->editCounter,
            'opted_in_page' => $this->getOptedInPage(),
        ];

        // Output the relevant format template.
        return $this->getFormattedResponse('editCounter/timecard', $ret);
    }

    /**
     * Search form for timecard.
     * @Route("/ec-timecard", name="EditCounterTimecardIndex")
     * @return Response
     */
    public function timecardIndexAction(): Response
    {
        $this->sections = ['timecard'];
        return $this->indexAction();
    }

    /**
     * Display the year counts section.
     * @Route(
     *     "/ec-yearcounts/{project}/{username}",
     *     name="EditCounterYearCounts",
     *     requirements={
     *         "username" = "(ipr-.+\/\d+[^\/])|([^\/]+)",
     *     }
     * )
     * @return Response
     * @codeCoverageIgnore
     */
    public function yearCountsAction(): Response
    {
        $this->setUpEditCounter();

        $ret = [
            'xtTitle' => $this->user->getUsername(),
            'xtPage' => 'EditCounter',
            'subtool_msg_key' => 'year-counts',
            'is_sub_request' => $this->isSubRequest,
            'user' => $this->user,
            'project' => $this->project,
            'ec' => $this->editCounter,
        ];

        // Output the relevant format template.
        return $this->getFormattedResponse('editCounter/yearcounts', $ret);
    }

    /**
     * Search form for year counts.
     * @Route("/ec-yearcounts", name="EditCounterYearCountsIndex")
     * @return Response
     */
    public function yearCountsIndexAction(): Response
    {
        $this->sections = ['year-counts'];
        return $this->indexAction();
    }

    /**
     * Display the month counts section.
     * @Route(
     *     "/ec-monthcounts/{project}/{username}",
     *     name="EditCounterMonthCounts",
     *     requirements={
     *         "username" = "(ipr-.+\/\d+[^\/])|([^\/]+)",
     *     }
     * )
     * @return Response
     * @codeCoverageIgnore
     */
    public function monthCountsAction(): Response
    {
        $this->setUpEditCounter();

        $ret = [
            'xtTitle' => $this->user->getUsername(),
            'xtPage' => 'EditCounter',
            'subtool_msg_key' => 'month-counts',
            'is_sub_request' => $this->isSubRequest,
            'user' => $this->user,
            'project' => $this->project,
            'ec' => $this->editCounter,
            'opted_in_page' => $this->getOptedInPage(),
        ];

        // Output the relevant format template.
        return $this->getFormattedResponse('editCounter/monthcounts', $ret);
    }

    /**
     * Search form for month counts.
     * @Route("/ec-monthcounts", name="EditCounterMonthCountsIndex")
     * @return Response
     */
    public function monthCountsIndexAction(): Response
    {
        $this->sections = ['month-counts'];
        return $this->indexAction();
    }

    /**
     * Display the user rights changes section.
     * @Route(
     *     "/ec-rightschanges/{project}/{username}",
     *     name="EditCounterRightsChanges",
     *     requirements={
     *         "username" = "(ipr-.+\/\d+[^\/])|([^\/]+)",
     *     }
     * )
     * @return Response
     * @codeCoverageIgnore
     */
    public function rightsChangesAction(): Response
    {
        $this->setUpEditCounter();

        $ret = [
            'xtTitle' => $this->user->getUsername(),
            'xtPage' => 'EditCounter',
            'is_sub_request' => $this->isSubRequest,
            'user' => $this->user,
            'project' => $this->project,
            'ec' => $this->editCounter,
        ];

        if ($this->getParameter('app.is_wmf')) {
            $ret['metaProject'] = $this->projectRepo->getProject('metawiki');
        }

        // Output the relevant format template.
        return $this->getFormattedResponse('editCounter/rights_changes', $ret);
    }

    /**
     * Search form for rights changes.
     * @Route("/ec-rightschanges", name="EditCounterRightsChangesIndex")
     * @return Response
     */
    public function rightsChangesIndexAction(): Response
    {
        $this->sections = ['rights-changes'];
        return $this->indexAction();
    }

    /************************ API endpoints ************************/

    /**
     * Get various log counts for the user as JSON.
     * @Route(
     *     "/api/user/log_counts/{project}/{username}",
     *     name="UserApiLogCounts",
     *     requirements={
     *         "username" = "(ipr-.+\/\d+[^\/])|([^\/]+)",
     *     }
     * )
     * @return JsonResponse
     * @codeCoverageIgnore
     */
    public function logCountsApiAction(): JsonResponse
    {
        $this->setUpEditCounter();

        return $this->getFormattedApiResponse([
            'log_counts' => $this->editCounter->getLogCounts(),
        ]);
    }

    /**
     * Get the namespace totals for the user as JSON.
     * @Route(
     *     "/api/user/namespace_totals/{project}/{username}",
     *     name="UserApiNamespaceTotals",
     *     requirements={
     *         "username" = "(ipr-.+\/\d+[^\/])|([^\/]+)",
     *     }
     * )
     * @return JsonResponse
     * @codeCoverageIgnore
     */
    public function namespaceTotalsApiAction(): JsonResponse
    {
        $this->setUpEditCounter();

        return $this->getFormattedApiResponse([
            'namespace_totals' => (object)$this->editCounter->namespaceTotals(),
        ]);
    }

    /**
     * Get the month counts for the user as JSON.
     * @Route(
     *     "/api/user/month_counts/{project}/{username}",
     *     name="UserApiMonthCounts",
     *     requirements={
     *         "username" = "(ipr-.+\/\d+[^\/])|([^\/]+)",
     *     }
     * )
     * @return JsonResponse
     * @codeCoverageIgnore
     */
    public function monthCountsApiAction(): JsonResponse
    {
        $this->setUpEditCounter();

        $ret = $this->editCounter->monthCounts();

        // Remove labels that are only needed by Twig views, and not consumers of the API.
        unset($ret['yearLabels']);
        unset($ret['monthLabels']);

        return $this->getFormattedApiResponse($ret);
    }

    /**
     * Get the timecard data as JSON.
     * @Route(
     *     "/api/user/timecard/{project}/{username}",
     *     name="UserApiTimeCard",
     *     requirements={
     *         "username" = "(ipr-.+\/\d+[^\/])|([^\/]+)",
     *     }
     * )
     * @return JsonResponse
     * @codeCoverageIgnore
     */
    public function timecardApiAction(): JsonResponse
    {
        $this->setUpEditCounter();

        return $this->getFormattedApiResponse([
            'timecard' => $this->editCounter->timeCard(),
        ]);
    }
}
