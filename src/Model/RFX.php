<?php
/**
 * An RFX object contains the parsed information for an RFX
 */

declare(strict_types = 1);

namespace App\Model;

/**
 * This class contains information about a single RfX page.
 * @codeCoverageIgnore
 */
class RFX extends Model
{
    /** @var array Data we parsed out of the page text */
    private $data;

    /** @var array Duplicate voters */
    private $duplicates;

    /** @var null|string Username of the user we're looking for. */
    private $userLookingFor;

    /** @var string Section we found the user we're looking for */
    private $userSectionFound;

    /**
     * Attempts to find a signature in $input using the default regex.
     * Returns matches.
     *
     * @param string $input   The line we're looking for
     * @param array  $matches Pointer to an array where we stash results
     *
     * @TODO: Make this cleaner
     *
     * @return int
     */
    protected function findSig(string $input, array &$matches): int
    {
        //Supports User: and User talk: wikilinks, {{fullurl}},
        // unsubstituted {{unsigned}}, unsubstituted {{unsigned2}},
        // anything that looks like a custom sig template
        // TODO: Cross-wiki this sucker
        $regexp
            = //1: Normal [[User:XX]] and [[User talk:XX]]
            "/\[\[[Uu]ser(?:[\s_][Tt]alk)?\:([^\]\|\/]*)(?:\|[^\]]*)?\]\]"
            //2: {{fullurl}} and {{unsigned}} templates
            . "|\{\{(?:[Ff]ullurl\:[Uu]ser(?:[\s_][Tt]alk)?\:|"
            . "[Uu]nsigned\|)([^\}\|]*)(?:|[\|\}]*)?\}\}"
            //3: {{User:XX/sig}} templates
            . "|(?:\{\{)[Uu]ser(?:[\s_][Tt]alk)?\:([^\}\/\|]*)"
            //4: {{unsigned2|Date|XX}} templates
            . "|\{\{[Uu]nsigned2\|[^\|]*\|([^\}]*)\}\}"
            //5: [[User:XX/sig]] links (compromise measure)
            . "|(?:\[\[)[Uu]ser\:([^\]\/\|]*)\/[Ss]ig[\|\]]/";

        return preg_match_all(
            $regexp,
            $input,
            $matches,
            PREG_OFFSET_CAPTURE
        );
    }

    /**
     * This function parses the wikitext and stores it within this function.
     * It's been split out to make this class testable
     *
     * @param array  $sectionArray Section names that we're looking for
     * @param string $rawWikiText  The text of the page we're parsing
     * @param string $dateRegexp   Valid Regular Expression for the end date
     */
    private function setUp(array $sectionArray, string $rawWikiText, string $dateRegexp): void
    {
        $this->data = [];

        $lines = explode("\n", $rawWikiText);

        $keys = join("|", $sectionArray);

        $lastSection = "";

        foreach ($lines as $line) {
            if (preg_match("/={1,6}\s?($keys)\s?={1,6}/i", $line, $matches)) {
                $lastSection = strtolower($matches[1]);
            } elseif ("" == $lastSection
                && preg_match(
                    "/$dateRegexp/i",
                    $line,
                    $matches
                )
            ) {
                $this->end = $matches[1];
            } elseif ("" != $lastSection
                && 0 === preg_match("/^\s*#?:.*/i", $line)
            ) {
                $this->findSig($line, $matches);
                if (!isset($matches[1][0])) {
                    continue;
                }
                $foundUser = trim($matches[1][0][0]);
                $this->data[$lastSection][] = $foundUser;
                if (strtolower($foundUser) === strtolower($this->userLookingFor)) {
                    $this->userSectionFound = $lastSection;
                }
            }
        }

        $final = [];    // initialize the final array
        $finalRaw = []; // Initialize the raw data array

        foreach (array_keys($this->data) as $key) {
            $finalRaw = array_merge($finalRaw, $this->data[$key]);
        }

        foreach ($finalRaw as $foundUsername) {
            $final[] = $foundUsername; // group all array's elements
        }

        $final = array_count_values($final); // find repetition and its count

        $final = array_diff($final, [1]);    // remove single occurrences

        $this->duplicates = array_keys($final);
    }

    /**
     * RFX constructor.
     *
     * @param string      $rawWikiText    The text of the page we're parsing
     * @param array       $sectionArray   Section names that we're looking for
     * @param string      $userNamespace  Plain text of the user namespace
     * @param string      $dateRegexp     Valid Regular Expression for the end date
     * @param string|null $userLookingFor User we're trying to find.
     */
    public function __construct(
        string $rawWikiText,
        array $sectionArray = ["Support", "Oppose", "Neutral"],
        string $userNamespace = "User",
        string $dateRegexp = "final .*end(?:ing|ed)?(?: no earlier than)? (.*?)? \(UTC\)",
        ?string $userLookingFor = null
    ) {
        $this->userLookingFor = $userLookingFor;

        $this->setUp($sectionArray, $rawWikiText, $dateRegexp);
    }

    /**
     * Which section we found the user we're looking for.
     *
     * @return string
     */
    public function getUserSectionFound(): string
    {
        return $this->userSectionFound;
    }

    /**
     * Returns data on the given section name.
     *
     * @param string $sectionName The section we're looking at
     *
     * @return array
     */
    public function getSection(string $sectionName): array
    {
        $sectionName = strtolower($sectionName);
        if (!isset($this->data[$sectionName])) {
            return [];
        } else {
            return $this->data[$sectionName];
        }
    }

    /**
     * Get an array of duplicate votes.
     *
     * @return array
     */
    public function getDuplicates(): array
    {
        return $this->duplicates;
    }
}
