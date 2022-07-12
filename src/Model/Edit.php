<?php
/**
 * This file contains only the Edit class.
 */

declare(strict_types = 1);

namespace App\Model;

use DateTime;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * An Edit is a single edit to a page on one project.
 */
class Edit extends Model
{
    /** @var int ID of the revision */
    protected $id;

    /** @var DateTime Timestamp of the revision */
    protected $timestamp;

    /** @var bool Whether or not this edit was a minor edit */
    protected $minor;

    /** @var int|string|null Length of the page as of this edit, in bytes */
    protected $length;

    /** @var int|string|null The diff size of this edit */
    protected $lengthChange;

    /** @var User - User object of who made the edit */
    protected $user;

    /** @var string The edit summary */
    protected $comment;

    /** @var string The SHA-1 of the wikitext as of the revision. */
    protected $sha;

    /** @var bool Whether this edit was later reverted. */
    protected $reverted;

    /**
     * Edit constructor.
     * @param Page $page
     * @param string[] $attrs Attributes, as retrieved by PageRepository::getRevisions()
     */
    public function __construct(Page $page, array $attrs = [])
    {
        $this->page = $page;

        // Copy over supported attributes
        $this->id = isset($attrs['id']) ? (int)$attrs['id'] : (int)$attrs['rev_id'];

        // Allow DateTime or string (latter assumed to be of format YmdHis)
        if ($attrs['timestamp'] instanceof DateTime) {
            $this->timestamp = $attrs['timestamp'];
        } else {
            $this->timestamp = DateTime::createFromFormat('YmdHis', $attrs['timestamp']);
        }

        $this->minor = '1' === $attrs['minor'];
        $this->length = (int)$attrs['length'];
        $this->lengthChange = (int)$attrs['length_change'];
        $this->user = $attrs['user'] ?? ($attrs['username'] ? new User($attrs['username']) : null);
        $this->comment = $attrs['comment'];

        if (isset($attrs['rev_sha1']) || isset($attrs['sha'])) {
            $this->sha = $attrs['rev_sha1'] ?? $attrs['sha'];
        }

        // This can be passed in to save as a property on the Edit instance.
        // Note that the Edit class knows nothing about it's value, and
        // is not capable of detecting whether the given edit was reverted.
        $this->reverted = isset($attrs['reverted']) ? (bool)$attrs['reverted'] : null;
    }

    /**
     * Get Edits given revision rows (JOINed on the page table).
     * @param Project $project
     * @param User $user
     * @param array $revs Each must contain 'page_title' and 'page_namespace'.
     * @return Edit[]
     */
    public static function getEditsFromRevs(Project $project, User $user, array $revs): array
    {
        return array_map(function ($rev) use ($project, $user) {
            /** @var Page $page Page object to be passed to the Edit constructor. */
            $page = Page::newFromRow($project, $rev);
            $rev['user'] = $user;

            return new self($page, $rev);
        }, $revs);
    }

    /**
     * Unique identifier for this Edit, to be used in cache keys.
     * @see Repository::getCacheKey()
     * @return string
     */
    public function getCacheKey(): string
    {
        return (string)$this->id;
    }

    /**
     * ID of the edit.
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Get the edit's timestamp.
     * @return DateTime
     */
    public function getTimestamp(): DateTime
    {
        return $this->timestamp;
    }

    /**
     * Get the edit's timestamp as a UTC string, as with YYYY-MM-DDTHH:MM:SS
     * @return string
     */
    public function getUTCTimestamp(): string
    {
        return $this->getTimestamp()->format('Y-m-d\TH:i:s');
    }

    /**
     * Year the revision was made.
     * @return string
     */
    public function getYear(): string
    {
        return $this->timestamp->format('Y');
    }

    /**
     * Get the numeric representation of the month the revision was made, with leading zeros.
     * @return string
     */
    public function getMonth(): string
    {
        return $this->timestamp->format('m');
    }

    /**
     * Whether or not this edit was a minor edit.
     * @return bool
     */
    public function getMinor(): bool
    {
        return $this->minor;
    }

    /**
     * Alias of getMinor()
     * @return bool Whether or not this edit was a minor edit
     */
    public function isMinor(): bool
    {
        return $this->getMinor();
    }

    /**
     * Length of the page as of this edit, in bytes.
     * @see Edit::getSize() Edit::getSize() for the size <em>change</em>.
     * @return int
     */
    public function getLength(): int
    {
        return $this->length;
    }

    /**
     * The diff size of this edit.
     * @return int Signed length change in bytes.
     */
    public function getSize(): int
    {
        return $this->lengthChange;
    }

    /**
     * Alias of getSize()
     * @return int The diff size of this edit
     */
    public function getLengthChange(): int
    {
        return $this->getSize();
    }

    /**
     * Get the user who made the edit.
     * @return User|null null can happen for instance if the username was suppressed.
     */
    public function getUser(): ?User
    {
        return $this->user;
    }

    /**
     * Set the User.
     * @param User $user
     */
    public function setUser(User $user): void
    {
        $this->user = $user;
    }

    /**
     * Get the edit summary.
     * @return string
     */
    public function getComment(): string
    {
        return (string)$this->comment;
    }

    /**
     * Get the edit summary (alias of Edit::getComment()).
     * @return string
     */
    public function getSummary(): string
    {
        return $this->getComment();
    }

    /**
     * Get the SHA-1 of the revision.
     * @return string|null
     */
    public function getSha(): ?string
    {
        return $this->sha;
    }

    /**
     * Was this edit reported as having been reverted?
     * The value for this is merely passed in from precomputed data.
     * @return bool|null
     */
    public function isReverted(): ?bool
    {
        return $this->reverted;
    }

    /**
     * Set the reverted property.
     * @param bool $revert
     */
    public function setReverted(bool $revert): void
    {
        $this->reverted = $revert;
    }

    /**
     * Get edit summary as 'wikified' HTML markup
     * @param bool $useUnnormalizedPageTitle Use the unnormalized page title to avoid
     *   an API call. This should be used only if you fetched the page title via other
     *   means (SQL query), and is not from user input alone.
     * @return string Safe HTML
     */
    public function getWikifiedComment(bool $useUnnormalizedPageTitle = false): string
    {
        return self::wikifyString(
            $this->getSummary(),
            $this->getProject(),
            $this->page,
            $useUnnormalizedPageTitle
        );
    }

    /**
     * Public static method to wikify a summary, can be used on any arbitrary string.
     * Does NOT support section links unless you specify a page.
     * @param string $summary
     * @param Project $project
     * @param Page $page
     * @param bool $useUnnormalizedPageTitle Use the unnormalized page title to avoid
     *   an API call. This should be used only if you fetched the page title via other
     *   means (SQL query), and is not from user input alone.
     * @static
     * @return string
     */
    public static function wikifyString(
        string $summary,
        Project $project,
        ?Page $page = null,
        bool $useUnnormalizedPageTitle = false
    ): string {
        $summary = htmlspecialchars(html_entity_decode($summary), ENT_NOQUOTES);

        // First link raw URLs. Courtesy of https://stackoverflow.com/a/11641499/604142
        $summary = preg_replace(
            '%\b(([\w-]+://?|www[.])[^\s()<>]+(?:\([\w\d]+\)|([^[:punct:]\s]|/)))%s',
            '<a target="_blank" href="$1">$1</a>',
            $summary
        );

        $sectionMatch = null;
        $isSection = preg_match_all("/^\/\* (.*?) \*\//", $summary, $sectionMatch);

        if ($isSection && isset($page)) {
            $pageUrl = $project->getUrl(false) . str_replace(
                '$1',
                $page->getTitle($useUnnormalizedPageTitle),
                $project->getArticlePath()
            );
            $sectionTitle = $sectionMatch[1][0];

            // Must have underscores for the link to properly go to the section.
            $sectionTitleLink = htmlspecialchars(str_replace(' ', '_', $sectionTitle));

            $sectionWikitext = "<a target='_blank' href='$pageUrl#$sectionTitleLink'>&rarr;</a>" .
                "<em class='text-muted'>" . htmlspecialchars($sectionTitle) . ":</em> ";
            $summary = str_replace($sectionMatch[0][0], $sectionWikitext, $summary);
        }

        $linkMatch = null;

        while (preg_match_all("/\[\[:?(.*?)]]/", $summary, $linkMatch)) {
            $wikiLinkParts = explode('|', $linkMatch[1][0]);
            $wikiLinkPath = htmlspecialchars($wikiLinkParts[0]);
            $wikiLinkText = htmlspecialchars(
                $wikiLinkParts[1] ?? $wikiLinkPath
            );

            // Use normalized page title (underscored, capitalized).
            $pageUrl = $project->getUrl(false) . str_replace(
                '$1',
                ucfirst(str_replace(' ', '_', $wikiLinkPath)),
                $project->getArticlePath()
            );

            $link = "<a target='_blank' href='$pageUrl'>$wikiLinkText</a>";
            $summary = str_replace($linkMatch[0][0], $link, $summary);
        }

        return $summary;
    }

    /**
     * Get edit summary as 'wikified' HTML markup (alias of Edit::getWikifiedSummary()).
     * @return string
     */
    public function getWikifiedSummary(): string
    {
        return $this->getWikifiedComment();
    }

    /**
     * Get the project this edit was made on
     * @return Project
     */
    public function getProject(): Project
    {
        return $this->getPage()->getProject();
    }

    /**
     * Get the full URL to the diff of the edit
     * @return string
     */
    public function getDiffUrl(): string
    {
        $project = $this->getProject();
        $path = str_replace('$1', 'Special:Diff/' . $this->id, $project->getArticlePath());
        return rtrim($project->getUrl(), '/') . $path;
    }

    /**
     * Get the full permanent URL to the page at the time of the edit
     * @return string
     */
    public function getPermaUrl(): string
    {
        $project = $this->getProject();
        $path = str_replace('$1', 'Special:PermaLink/' . $this->id, $project->getArticlePath());
        return rtrim($project->getUrl(), '/') . $path;
    }

    /**
     * Was the edit a revert, based on the edit summary?
     * @param ContainerInterface $container The DI container.
     * @return bool
     */
    public function isRevert(ContainerInterface $container): bool
    {
        $automatedEditsHelper = $container->get('app.automated_edits_helper');
        return $automatedEditsHelper->isRevert($this->comment, $this->getProject());
    }

    /**
     * Get the name of the tool that was used to make this edit.
     * @param ContainerInterface $container The DI container.
     * @return array|false The name of the tool that was used to make the edit
     */
    public function getTool(ContainerInterface $container)
    {
        $automatedEditsHelper = $container->get('app.automated_edits_helper');
        return $automatedEditsHelper->getTool((string)$this->comment, $this->getProject());
    }

    /**
     * Was the edit (semi-)automated, based on the edit summary?
     * @param ContainerInterface $container
     * @return bool
     */
    public function isAutomated(ContainerInterface $container): bool
    {
        return (bool)$this->getTool($container);
    }

    /**
     * Was the edit made by a logged out user?
     * @return bool|null
     */
    public function isAnon(): ?bool
    {
        return $this->getUser() ? $this->getUser()->isAnon() : null;
    }

    /**
     * Get HTML for the diff of this Edit.
     * @return string|null Raw HTML, must be wrapped in a <table> tag. Null if no comparison could be made.
     */
    public function getDiffHtml(): ?string
    {
        return $this->getRepository()->getDiffHtml($this);
    }

    /**
     * Formats the data as an array for use in JSON APIs.
     * @param bool $includeUsername False for most tools such as Global Contribs, AutoEdits, etc.
     * @return array
     * @internal This method assumes the Edit was constructed with data already filled in from a database query.
     */
    public function getForJson(bool $includeUsername = false, bool $includeProject = false): array
    {
        $nsId = $this->getPage()->getNamespace();
        $pageTitle = $this->getPage()->getTitle(true);

        if ($nsId > 0) {
            $nsName = $this->getProject()->getNamespaces()[$nsId];
            $pageTitle = preg_replace("/^$nsName:/", '', $pageTitle);
        }

        $ret = [
            'page_title' => $pageTitle,
            'page_namespace' => $nsId,
            'rev_id' => $this->id,
            'timestamp' => $this->getUTCTimestamp(),
            'minor' => $this->minor,
            'length' => $this->length,
            'length_change' => $this->lengthChange,
            'comment' => $this->comment,
        ];
        if (null !== $this->reverted) {
            $ret['reverted'] = $this->reverted;
        }
        if ($includeUsername) {
            $ret = [ 'username' => $this->getUser()->getUsername() ] + $ret;
        }
        if ($includeProject) {
            $ret = [ 'project' => $this->getProject()->getDomain() ] + $ret;
        }

        return $ret;
    }
}
