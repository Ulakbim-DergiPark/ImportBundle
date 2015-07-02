<?php
/**
 * Date: 2.07.15
 * Time: 16:31
 */

namespace Okulbilisim\OjsToolsBundle\Command;


use Doctrine\MongoDB\EagerCursor;
use Doctrine\ODM\MongoDB\Cursor;
use Doctrine\ODM\MongoDB\DocumentManager;
use Ojs\JournalBundle\Document\WaitingFiles;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputInterface;

class DownloadWaitingFilesCommand extends ContainerAwareCommand
{

    /** @var  DocumentManager */
    protected $dm;
    /** @var  OutputInterface */
    protected $output;

    protected $rootDir;

    /**
     * Configure Command.
     */
    protected function configure()
    {
        gc_collect_cycles();
        $this
            ->setName('ojs:waiting_files:download')
            ->setDescription('Download waiting files');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return int|null|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {

        try {
            $this->dm = $this->getContainer()->get('doctrine.odm.mongodb.document_manager');
            $this->rootDir = dirname($this->getContainer()->get('kernel')->getRootDir());
            $kernel = $this->getContainer()->get('kernel');
            $application = new Application($kernel);
            $application->setAutoExit(false);
            $this->output = $output;
            /** @var \Doctrine\ODM\MongoDB\EagerCursor $files */
            $files = $this->getFiles();
            foreach ($files as $file) {
                $this->download($file);
            }


        } catch (\Exception $e) {
            $this->output->writeln("<error>{$e->getMessage()}</error>");
        }

    }

    public function getFiles()
    {
        $qb = $this->dm->createQueryBuilder("OjsJournalBundle:WaitingFiles")->eagerCursor(true);
        $qb->where("function() { return (typeof this.downloaded ==='undefined' || this.downloaded==false); }");

        /** @var \Doctrine\ODM\MongoDB\EagerCursor $files */
        $files = $qb->getQuery()->execute();
        return $files;
    }

    public function download(WaitingFiles $file)
    {
        $headers = get_headers($file->getUrl(), 1);
        if ($headers['Content-Type'] == "text/html")
            return;
        $fullPath = $this->rootDir . DIRECTORY_SEPARATOR . "web" . DIRECTORY_SEPARATOR . $file->getPath();
        if (!is_dir(dirname($fullPath))) {
            mkdir(dirname($fullPath), 0777, true);
        }
        $wrap = \fopen($fullPath, "a+");
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $file->getUrl());
        curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FILE, $wrap);
        curl_exec($ch);
        curl_close($ch);
        $this->output->writeln("<info>{$file->getPath()} indirildi.</info>");
    }
} 