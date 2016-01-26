<?php

namespace OkulBilisim\OjsImportBundle\Importer\PKP;

use DateTime;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Exception;
use Ojs\JournalBundle\Entity\Journal;
use Ojs\JournalBundle\Entity\Lang;
use Ojs\JournalBundle\Entity\Publisher;
use OkulBilisim\OjsImportBundle\Importer\Importer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;

class JournalImporter extends Importer
{
    /**
     * @var Journal
     */
    private $journal;

    /**
     * @var array
     */
    private $settings;

    /**
     * @var UserImporter
     */
    private $userImporter;

    /**
     * @var SectionImporter
     */
    private $sectionImporter;

    /**
     * @var IssueImporter
     */
    private $issueImporter;

    /**
     * @var ArticleImporter
     */
    private $articleImporter;

    /**
     * JournalImporter constructor.
     * @param Connection $dbalConnection
     * @param EntityManager $em
     * @param OutputInterface $consoleOutput
     * @param LoggerInterface $logger
     * @param UserImporter $ui
     */
    public function __construct(
        Connection $dbalConnection,
        EntityManager $em,
        LoggerInterface $logger,
        OutputInterface $consoleOutput,
        UserImporter $ui)
    {
        parent::__construct($dbalConnection, $em, $logger, $consoleOutput);

        $this->userImporter = $ui;
        $this->sectionImporter = new SectionImporter($this->dbalConnection, $this->em, $this->logger, $consoleOutput);
        $this->issueImporter = new IssueImporter($this->dbalConnection, $this->em, $this->logger, $consoleOutput);
        $this->articleImporter = new ArticleImporter(
            $this->dbalConnection, $this->em, $logger, $consoleOutput, $this->userImporter
        );
    }

    /**
     * Imports the journal with given ID
     * @param  int $id Journal's ID
     * @return array New IDs as keys, old IDs as values
     * @throws Exception
     * @throws \Doctrine\DBAL\DBALException
     */
    public function importJournal($id)
    {
        $this->consoleOutput->writeln("Importing the journal...");

        $journalSql = "SELECT path, primary_locale FROM journals WHERE journal_id = :id LIMIT 1";
        $journalStatement = $this->dbalConnection->prepare($journalSql);
        $journalStatement->bindValue('id', $id);
        $journalStatement->execute();

        $settingsSql = "SELECT locale, setting_name, setting_value FROM journal_settings WHERE journal_id = :id";
        $settingsStatement = $this->dbalConnection->prepare($settingsSql);
        $settingsStatement->bindValue('id', $id);
        $settingsStatement->execute();

        $pkpJournal = $journalStatement->fetch();
        $pkpSettings = $settingsStatement->fetchAll();
        $primaryLocale = $pkpJournal['primary_locale'];
        $languageCode = substr($primaryLocale, 0, 2);

        !$pkpJournal && die('Journal not found.' . PHP_EOL);
        $this->consoleOutput->writeln("Reading journal settings...");

        foreach ($pkpSettings as $setting) {
            $locale = !empty($setting['locale']) ? $setting['locale'] : $primaryLocale;
            $name = $setting['setting_name'];
            $value = $setting['setting_value'];
            $this->settings[$locale][$name] = $value;
        }

        $this->journal = new Journal();
        $this->journal->setStatus(1);
        $this->journal->setPublished(true);
        $this->journal->setSlug($pkpJournal['path']);

        // Fill translatable fields in all available languages
        foreach ($this->settings as $fieldLocale => $fields) {
            $this->journal->setCurrentLocale(substr($fieldLocale, 0, 2));

            !empty($fields['title']) ?
                $this->journal->setTitle($fields['title']) :
                $this->journal->setTitle('Unknown Journal');

            !empty($fields['description']) ?
                $this->journal->setDescription($fields['description']) :
                $this->journal->setDescription('-');
        }

        $this->journal->setCurrentLocale($primaryLocale);

        !empty($this->settings[$primaryLocale]['printIssn']) ?
            $this->journal->setIssn($this->settings[$primaryLocale]['printIssn']) :
            $this->journal->setIssn('1234-5679');

        !empty($this->settings[$primaryLocale]['onlineIssn']) ?
            $this->journal->setEissn($this->settings[$primaryLocale]['onlineIssn']) :
            $this->journal->setEissn('1234-5679');

        $date = sprintf('%d-01-01 00:00:00',
            !empty($this->settings[$primaryLocale]['initialYear']) ?
                $this->settings[$primaryLocale]['initialYear'] : '2015');
        $this->journal->setFounded(DateTime::createFromFormat('Y-m-d H:i:s', $date));

        // Set view and download counts
        !empty($this->settings[$primaryLocale]['total_views']) ?
            $this->journal->setViewCount($this->settings[$primaryLocale]['total_views']) :
            $this->journal->setViewCount(0);
        !empty($this->settings[$primaryLocale]['total_downloads']) ?
            $this->journal->setDownloadCount($this->settings[$primaryLocale]['total_downloads']) :
            $this->journal->setDownloadCount(0);

        // Set publisher
        !empty($this->settings[$primaryLocale]['publisherInstitution']) ?
            $this->importAndSetPublisher($this->settings[$primaryLocale]['publisherInstitution'], $primaryLocale) :
            $this->journal->setPublisher($this->getUnknownPublisher());

        // Use existing languages or create if needed
        $language = $this->em
            ->getRepository('OjsJournalBundle:Lang')
            ->findOneBy(['code' => $languageCode]);
        $this->journal->setMandatoryLang($language ? $language : $this->createLanguage($languageCode));
        $this->journal->addLanguage($language ? $language : $this->createLanguage($languageCode));

        $this->consoleOutput->writeln("Read journal's settings.");
        $this->em->beginTransaction(); // Outer transaction

        try {
            $this->em->beginTransaction(); // Inner transaction
            $this->em->persist($this->journal);
            $this->em->flush();
            $this->em->commit();
        } catch (Exception $exception) {
            $this->em->rollback();
            throw $exception;
        }

        $this->consoleOutput->writeln("Imported journal #" . $id);

        // Those below also create their own inner transactions
        $createdSections = $this->sectionImporter->importJournalSections($id, $this->journal->getId());
        $createdIssues = $this->issueImporter->importJournalIssues($id, $this->journal->getId(), $createdSections);
        $this->articleImporter->importArticles($id, $this->journal->getId(), $createdIssues, $createdSections);

        $this->em->commit();
        return ['new' => $this->journal->getId(), 'old' => $id];
    }

    /**
     * Imports the publisher with given name and assigns it to
     * the journal. It uses the one from the database in case
     * it exists.
     * @param String $name Publisher's name
     * @param String $locale Locale of the settings
     */
    private function importAndSetPublisher($name, $locale)
    {
        $publisher = $this->em
            ->getRepository('OjsJournalBundle:Publisher')
            ->findOneBy(['name' => $name]);

        if (!$publisher) {
            $url = !empty($this->settings[$locale]['publisherUrl']) ? $this->settings[$locale]['publisherUrl'] : null;
            $publisher = $this->createPublisher($this->settings[$locale]['publisherInstitution'], $url);

            foreach ($this->settings as $fieldLocale => $fields) {
                $publisher->setCurrentLocale(substr($fieldLocale, 0, 2));
                !empty($fields['publisherNote']) ?
                    $publisher->setAbout($fields['publisherNote']) :
                    $publisher->setAbout('-');
            }
        }

        $this->journal->setPublisher($publisher);
    }

    /**
     * Fetches the publisher with the name "Unknown Publisher".
     * @return Publisher Publisher with the name "Unknown Publisher"
     */
    private function getUnknownPublisher()
    {
        $publisher = $this->em
            ->getRepository('OjsJournalBundle:Publisher')
            ->findOneBy(['name' => 'Unknown Publisher']);

        if (!$publisher) {
            $publisher = $this->createPublisher('Unknown Publisher', 'http://example.com');
            $publisher->setCurrentLocale('en');
            $publisher->setAbout('-');
            $this->em->persist($publisher);
        }

        return $publisher;
    }

    /**
     * Creates a publisher with given properties.
     * @param  String $name
     * @param  String $url
     * @return Publisher Created publisher
     */
    private function createPublisher($name, $url)
    {
        $publisher = new Publisher();
        $publisher->setName($name);
        $publisher->setEmail('publisher@example.com');
        $publisher->setAddress('-');
        $publisher->setPhone('-');
        $publisher->setUrl($url);

        $this->em->persist($publisher);

        return $publisher;
    }

    /**
     * Creates a language with given language code.
     * @param  String $code Language code
     * @return Lang Created language
     */
    private function createLanguage($code)
    {
        $nameMap = array(
            'tr' => 'Türkçe',
            'en' => 'English',
            'de' => 'Deutsch',
            'fr' => 'Français',
            'ru' => 'Русский язык',
        );

        $lang = new Lang();
        $lang->setCode($code);
        !empty($nameMap[$code]) ?
            $lang->setName($nameMap[$code]) :
            $lang->setName('Unknown Language');

        $this->em->persist($lang);

        return $lang;
    }
}