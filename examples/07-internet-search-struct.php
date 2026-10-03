<?php

require_once __DIR__ . "/../vendor/autoload.php";

class T_Address
{
    public string $company;

    public string $name;
    public string $zip;
    public string $city;
    public string $street;

    /**
     * The website of the company starting with http:// or https://
     * @var string
     */
    public string $website;

    /**
     * The email address of the company or n/a
     * @var string
     */
    public string $email;

    /**
     * The phone number of the company or n/a
     * @var string
     */
    public string $phone;

    /**
     * The URL of the job posting or n/a
     * @var string
     */
    public string $stellenanzeige_url;

    /**
     * The title of the job posting or n/a
     * @var string
     */
    public string $stellenanzeige_title;

    /**
     * The date of the job posting or n/a
     * @var string
     */
    public string $stellenanzeige_datum;

    /**
     * The contact person of the job posting or n/a
     * @var string
     */
    public string $stellenanzeige_ansprechpartner;

    /**
     * The email of the contact person or n/a
     * @var string
     */
    public string $stellenanzeige_ansprechpartner_email;
}

class T_Addresses
{
    /**
     * @var T_Address[]
     */
    public array $addresses;

    /**
     * Print what you did, what you found and what you searched for. This is for debugging purposes.
     * Fasse dich kurz.
     *
     * @var string
     */
    public string $source;

    /**
     * If there are more results available, this will be true. If false, you can stop searching.
     *
     * @var bool
     */
    public bool $moreResultsAvailable;
}


$prompt = "suche bei google und find all arztpraxen in essen, germany, die aktuell nach mfa suchen. Frage nicht. mach einfach.";

// Liste mit praxisnamen, die bereit gefunden wurden, um doppelte Ergebnisse zu vermeiden
$alreadyFound = [];

 do {
    $result = phore_ai_struct([
        $prompt,
        new \Phore\AiHarness\PromptType\StructPrompt($alreadyFound, "alreadyFound", "Diese Praxen sollen bei der Suche übergangen werden, da wir ihre daten bereits haben."),
        new \Phore\AiHarness\ToolType\WebAccessTool()], T_Addresses::class);

    foreach( $result->addresses as $address) {
        if (in_array($address->company, $alreadyFound)) {
            continue;
        }
        $alreadyFound[] = $address->company;
    }
    print_r ($result);
    echo "\nUsage: " . get_last_ai_response()->getUsage();

} while ($result->moreResultsAvailable);
