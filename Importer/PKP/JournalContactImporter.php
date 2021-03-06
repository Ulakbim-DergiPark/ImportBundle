<?php

namespace Ojs\ImportBundle\Importer\PKP;

use Ojs\JournalBundle\Entity\Journal;
use Ojs\JournalBundle\Entity\JournalContact;
use Ojs\ImportBundle\Importer\Importer;

class JournalContactImporter extends Importer
{
    /**
     * Imports contacts of the given journal.
     * @param Journal $journal The journal whose contacts are going to be imported
     * @param int $journalId Old ID of the journal
     * @throws \Doctrine\DBAL\DBALException
     */
    public function importContacts($journal, $journalId)
    {
        $this->consoleOutput->writeln("Importing journal's contacts...");

        $settingsSql = "SELECT locale, setting_name, setting_value FROM journal_settings WHERE journal_id = :id";
        $settingsStatement = $this->dbalConnection->prepare($settingsSql);
        $settingsStatement->bindValue('id', $journalId);
        $settingsStatement->execute();

        $settings = array();
        $pkpSettings = $settingsStatement->fetchAll();

        foreach ($pkpSettings as $setting) {
            $name = $setting['setting_name'];
            $value = $setting['setting_value'];
            $settings[$name] = $value;
        }

        $contact = new JournalContact();
        $contact->setFullName($settings['contactName']);
        $contact->setEmail($settings['contactEmail']);
        $contact->setPhone($settings['contactPhone']);
        $contact->setAddress($settings['contactMailingAddress']);

        $types = $this->em->getRepository('OjsJournalBundle:ContactTypes')->findAll();
        !empty($types) && $contact->setContactType($types[0]);

        $journal->addJournalContact($contact);
    }
}
