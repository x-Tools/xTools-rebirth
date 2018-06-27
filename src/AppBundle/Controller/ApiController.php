<?php
/**
 * This file contains only the ApiController class.
 */

namespace AppBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use FOS\RestBundle\Controller\Annotations as Rest;
use FOS\RestBundle\Controller\FOSRestController;
use FOS\RestBundle\View\View;
use Xtools\ProjectRepository;
use Xtools\Edit;

/**
 * Serves the external API of XTools.
 */
class ApiController extends FOSRestController
{
    /**
     * Get domain name, URL, and API URL of the given project.
     * @Rest\Get("/api/project/normalize/{project}")
     * @param string $project Project database name, URL, or domain name.
     * @return View
     */
    public function normalizeProject($project)
    {
        $proj = ProjectRepository::getProject($project, $this->container);

        if (!$proj->exists()) {
            return new View(
                [
                    'error' => "$project is not a valid project",
                ],
                Response::HTTP_NOT_FOUND
            );
        }

        return new View(
            [
                'domain' => $proj->getDomain(),
                'url' => $proj->getUrl(),
                'api' => $proj->getApiUrl(),
                'database' => $proj->getDatabaseName(),
            ],
            Response::HTTP_OK
        );
    }

    /**
     * Get all namespaces of the given project. This endpoint also does the same thing
     * as the /project/normalize endpoint, returning other basic info about the project.
     * @Rest\Get("/api/project/namespaces/{project}")
     * @param string $project The project name.
     * @return View
     */
    public function namespaces($project)
    {
        $proj = ProjectRepository::getProject($project, $this->container);

        if (!$proj->exists()) {
            return new View(
                [
                    'error' => "$project is not a valid project",
                ],
                Response::HTTP_NOT_FOUND
            );
        }

        return new View(
            [
                'domain' => $proj->getDomain(),
                'url' => $proj->getUrl(),
                'api' => $proj->getApiUrl(),
                'database' => $proj->getDatabaseName(),
                'namespaces' => $proj->getNamespaces(),
            ],
            Response::HTTP_OK
        );
    }

    /**
     * Get assessment data for a given project.
     * @Rest\Get("/api/project/assessments/{project}")
     * @param string $project The project name.
     * @return View
     */
    public function assessments($project = null)
    {
        if ($project === null) {
            return new View(
                [
                    'projects' => array_keys($this->container->getParameter('assessments')),
                    'config' => $this->container->getParameter('assessments'),
                ],
                Response::HTTP_OK
            );
        }

        $proj = ProjectRepository::getProject($project, $this->container);

        if (!$proj->exists()) {
            return new View(
                [
                    'error' => "$project is not a valid project",
                ],
                Response::HTTP_NOT_FOUND
            );
        }

        return new View(
            [
                'project' => $proj->getDomain(),
                'assessments' => $proj->getPageAssessments()->getConfig(),
            ],
            Response::HTTP_OK
        );
    }

    /**
     * Transform given wikitext to HTML using the XTools parser.
     * Wikitext must be passed in as the query 'wikitext'.
     * @Rest\Get("/api/project/parser/{project}")
     * @param  Request $request Provided by Symfony.
     * @param  string $project Project domain such as en.wikipedia.org
     * @return string Safe HTML.
     */
    public function wikify(Request $request, $project)
    {
        $projectData = ProjectRepository::getProject($project, $this->container);
        return Edit::wikifyString($request->query->get('wikitext'), $projectData);
    }
}
