<?php

namespace Xtools;

use Xtools\User;
use AppBundle\Helper\AutomatedEditsHelper;
use DateTime;

/**
 * An Edit is a single edit to a page on one project.
 */
class Edit extends Model
{

    /** @var Page the page associated with this edit */
    protected $page;

    /** @var int ID of the revision */
    protected $id;

    /** @var DateTime Timestamp fo the revision */
    protected $timestamp;

    /** @var bool Whether or not this edit was a minor edit */
    protected $minor;

    /** @var int Length of the page as of this edit, in bytes */
    protected $length;

    /** @var int The diff size of this edit */
    protected $length_change;

    /** @var User - User object of who made the edit */
    protected $user;

    /** @var string The edit summary */
    protected $comment;

    /**
     * Edit constructor.
     * @param Page $page
     * @param string[] $attrs Attributes, as retrieved by PagesRepository->getRevisions()
     */
    public function __construct(Page $page, $attrs)
    {
        $this->page = $page;

        // Copy over supported attributes
        $this->id = (int) $attrs['id'];
        $this->timestamp = DateTime::createFromFormat('YmdHis', $attrs['timestamp']);
        $this->minor = $attrs['minor'] === '1';
        $this->length = (int) $attrs['length'];
        $this->length_change = (int) $attrs['length_change'];
        $this->user = new User($attrs['username']);
        $this->comment = $attrs['comment'];
    }

    /**
     * @return Page
     */
    public function getPage()
    {
        return $page;
    }

    /**
     * @return int ID of the revision
     */
    public function getId()
    {
        return $id;
    }

    /**
     * @return DateTime Timestamp fo the revision
     */
    public function getTimestamp()
    {
        return $timestamp;
    }

    /**
     * @return bool Whether or not this edit was a minor edit
     */
    public function getMinor()
    {
        return $minor;
    }

    /**
     * @return int Length of the page as of this edit, in bytes
     */
    public function getLength()
    {
        return $length;
    }

    /**
     * @return int The diff size of this edit
     */
    public function getSize()
    {
        return $length_change;
    }

    /**
     * Alias of getSize()
     * @return int The diff size of this edit
     */
    public function getLengthChange()
    {
        return $this->getSize();
    }

    /**
     * @return User - User object of who made the edit
     */
    public function getUser()
    {
        return $user;
    }

    /**
     * @return string The edit summary
     */
    public function getComment()
    {
        return $comment;
    }

    /**
     * Alias of getComment()
     * @return string The edit summary
     */
    public function getSummary()
    {
        return $this->getComment();
    }

    /**
     * Get the project this edit was made on
     * @return Project
     */
    public function getProject()
    {
        return $this->$page->getProject();
    }

    /**
     * Was the edit a revert, based on the edit summary?
     * @return bool
     */
    public function isRevert()
    {
        $automatedEditsHelper = $this->container->get('app.automated_edits_helper');
        return $automatedEditsHelper->isRevert($this->comment);
    }

    /**
     * Was the edit (semi-)automated, based on the edit summary?
     * @return bool
     */
    public function isAutomated()
    {
        $automatedEditsHelper = $this->container->get('app.automated_edits_helper');
        return $automatedEditsHelper->isAutomated($this->comment);
    }
}
