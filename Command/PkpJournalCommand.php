<?php

namespace Ojs\ImportBundle\Command;

use Ojs\CoreBundle\Helper\StringHelper;
use Ojs\ImportBundle\Helper\ImportCommand;
use Ojs\ImportBundle\Importer\PKP\JournalImporter;
use Ojs\ImportBundle\Importer\PKP\JournalUserImporter;
use Ojs\ImportBundle\Importer\PKP\UserImporter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Stopwatch\Stopwatch;

class PkpJournalCommand extends ImportCommand
{
    protected function configure()
    {
        $this
            ->setName('ojs:import:pkp:journal')
            ->setDescription('Import a journal from PKP/OJS')
            ->addArgument('id', InputArgument::REQUIRED, 'Journal ID');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        parent::execute($input, $output);
        $userManager = $this->getContainer()->get('fos_user.user_manager');
        $locale = $this->getContainer()->getParameter('locale');
        $tokenGenrator = $this->getContainer()->get('fos_user.util.token_generator');
        $userImporter = new UserImporter($this->connection, $this->em, $this->logger, $output, $userManager, $tokenGenrator, $locale);

        $stopwatch = new Stopwatch();
        $stopwatch->start('journal_import');

        $journalImporter = new JournalImporter($this->connection, $this->em, $this->logger, $output, $userImporter);
        $ids = $journalImporter->importJournal($input->getArgument('id'));

        $journalUserImporter = new JournalUserImporter($this->connection, $this->em, $this->logger, $output);
        $journalUserImporter->importJournalUsers($ids['new'], $ids['old'], $userImporter);

        $event = $stopwatch->stop('journal_import');
        $output->writeln('Duration: ' . StringHelper::formatMilliseconds($event->getDuration()));
    }
}
