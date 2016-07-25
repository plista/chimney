<?php

/*
 * This file is part of Plista Chimney.
 *
 * (c) plista GmbH
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Plista\Chimney\Command;

use Plista\Chimney\Changelog\ChangelogList;
use Plista\Chimney\Changelog\ChangelogSection;
use Plista\Chimney\Changelog\Generator;
use Plista\Chimney\Changelog\Template;
use Plista\Chimney\Command\Make;
use Plista\Chimney\Command\Fetch\AuthorLoader;
use Plista\Chimney\Command\Fetch\ChangelogUpdaterFactory;
use Plista\Chimney\Command\Make\OutputMessage;
use Plista\Chimney\Command\Make\ScriptExecutor;
use Plista\Chimney\Entity\Author;
use Plista\Chimney\Entity\DateTime;
use Plista\Chimney\Entity\Release;
use Plista\Chimney\Entity\Version;
use Plista\Chimney\Entity\VersionExportable;
use Plista\Chimney\Entity\VersionIncrementor;
use Plista\Chimney\Export;
use Plista\Chimney\Import\LogConverter;
use Plista\Chimney\Import\LogParser;
use Plista\Chimney\Import\VersionParser;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @todo refactor
 */
class MakeCommand extends ContainerAwareCommand
{
    const ARG_TYPE = 'type';
    const OPT_PACKAGE = 'package';
    const OPT_CHANGELOG = 'changelog';
    const OPT_REV = 'rev';
    const OPT_ALLOW_MAJOR = 'major';
    const OPT_POSTRUN = 'post-run';

    const PLACEHOLDER_VERSION = '%VER%';
    const PLACEHOLDER_PACKAGE_NAME = '%PACKAGE%';
    const PLACEHOLDER_CHANGELOG_FILE = '%FILE%';
    const PLACEHOLDER_CHANGELOG_ADDON = '%ADDON%';

	/**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('make')
            ->setDescription('Make the changelog')
            ->setDefinition([])
            ->addArgument(
                self::ARG_TYPE,
                InputArgument::REQUIRED,
                'Changelog type. Currently supported types: debian, md'
            )
            ->addOption(
                self::OPT_PACKAGE,
                null,
                InputOption::VALUE_REQUIRED,
                'Package name. It is mandatory when making a debian changelog'
            )
            ->addOption(
                self::OPT_CHANGELOG,
                null,
                InputOption::VALUE_REQUIRED,
                'Changelog file location. Mandatory when run not ouf the parent folder of the repository'
            )
            ->addOption(
                self::OPT_POSTRUN,
                null,
                InputOption::VALUE_REQUIRED,
                'A full-path to a script with parameters to be run right after Chimney finishes its work. This is the way Chimney can be used as a part of Continuous Delivery automation. The main feature of this option is the placeholders. Using the placeholders you can pass results of Chimney\'s work to a post-run script. The next placeholders are supported: %VERSION%, %PACKAGE%, %CHANGELOGFILE%. The option decreases the verbosity'
            )
            ->addOption(
                self::OPT_REV,
                null,
                InputOption::VALUE_OPTIONAL,
                'Sets the revision in Git repository, after which log entries must be collected. If not set, the program will try to detect the latest version-tagged revision'
            )
            ->addOption(
                self::OPT_ALLOW_MAJOR,
                null,
                InputOption::VALUE_NONE,
                'Allows major releases. Be default there only can be minor or patches ones. Activate this option only if you have a well-functioning GIT workflow'
            )
            ->setHelp(<<<EOT
The <info>make</info> command reads git log from the current folder's repository, generates a new release changelog based on it and adds it to the projects changelog
EOT
           );
    }

    /**
     * @param InputInterface $input
     * @return mixed|string
     */
    private function getChangelogPath(InputInterface $input)
    {
        $path = $input->getOption(self::OPT_CHANGELOG);
        if ($path) {
            return $path;
        }
        switch ($input->getArgument(self::ARG_TYPE)) {
            case 'debian':
                $path = getcwd() . DIRECTORY_SEPARATOR . 'debian/changelog';
                break;
            case 'md':
                $path = getcwd() . DIRECTORY_SEPARATOR . 'CHANGELOG.md';
                break;
            default:
                throw new Make\ExitException(
                    "The changelog type is not recognized",
                    Make\ExitException::STATUS_CHANGELOG_TYPE_UNKNOWN
                );
        }
        if (!file_exists($path)) {
            throw new Make\ExitException(
                "The changelog cannot be found. Run the program out the parent folder of the repository or pass it as parameter",
                Make\ExitException::STATUS_CHANGELOG_FILE_NOT_FOUND
            );
        }
        return $path;
    }

    /**
     * {@inheritdoc}
     * @todo To be refactored. It's in fact an entry point of the application with all dependencies inverted. So it's not so easy to rework this command.
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $outputMessage = new OutputMessage();

            $this->setPlaceholder(self::PLACEHOLDER_PACKAGE_NAME, $this->getPackageName($input));
            if ($this->isDebian($input) && !$this->getPlaceholder(self::PLACEHOLDER_PACKAGE_NAME)) {
                throw new Make\ExitException(
                    "The \"package\" option must set when generating a debian changelog",
                    Make\ExitException::STATUS_ILLEGAL_COMMAND
                );
            }
            $this->setPlaceholder(self::PLACEHOLDER_CHANGELOG_FILE, $this->getChangelogPath($input));

            $lastRev = $this->getLastRev($input);
            $logOutput = $this->getGitCommand()->getLogAfter($lastRev);

            $version = $this->getVersion(new VersionParser($lastRev));
            $release = $this->getRelease($version);
            $release->setPackageName($this->getPlaceholder(self::PLACEHOLDER_PACKAGE_NAME));

            if ('' === trim($logOutput)) {
                throw new Make\ExitException("No new changes detected", Make\ExitException::STATUS_NO_CHANGES);
            }

            $logSection = new ChangelogSection($release);
            foreach ((new LogConverter($logOutput))->iterateEntries(new LogParser()) as $entry) {
                if ($entry->getChange()->isIgnore()) {
                    continue;
                }
                $logSection->addEntry($entry);
            }

            $versionIncrementor = new VersionIncrementor($logSection);
            if (!$input->getOption(self::OPT_ALLOW_MAJOR)) {
                $versionIncrementor->denyMajor();
            }
            $versionIncrementor->increment($version);
            $this->setPlaceholder(self::PLACEHOLDER_VERSION, $version->export());

            $logList = new ChangelogList();
            $logList->addSection($logSection);

            $this->setPlaceholder(
                self::PLACEHOLDER_CHANGELOG_ADDON,
                (new ChangelogUpdaterFactory())
                    ->create($input->getArgument(self::ARG_TYPE), new Template\Loader())
                    ->append(
                        new Export\ChangelogFile($this->getPlaceholder(self::PLACEHOLDER_CHANGELOG_FILE)),
                        new Generator($logList, new Template\Markup())
                    )
            );

            $outputMessage->appendN('Chimney: changelog updated');
            $outputMessage->appendChangelogInfo(
                $this->getPlaceholder(self::PLACEHOLDER_CHANGELOG_ADDON),
                $this->getPlaceholder(self::PLACEHOLDER_CHANGELOG_FILE)
            );

            $postRun = $input->getOption(self::OPT_POSTRUN);
            if ($postRun) {
                $result = (new ScriptExecutor($postRun))->execWithPlaceholders(
                    $this->getPlaceholderManagerService(),
                    $this->placeholders,
                    $this->getCommandExecutor()
                );
                foreach ($result as $line) {
                    $outputMessage->appendN($line);
                }
            }

            $output->writeln($outputMessage->get());
        }
        catch (Make\ExitException $e) {
            $this->setError($output, $e->getMessage());
            return $e->getCode();
        }
    }

    /**
     * @param InputInterface $input
     * @return mixed
     */
    private function getPackageName(InputInterface $input)
    {
        return $input->getOption(self::OPT_PACKAGE);
    }

    /**
     * @param InputInterface $input
     * @return bool
     */
    private function isDebian(InputInterface $input)
    {
        return ChangelogUpdaterFactory::TYPE_DEBIAN === $input->getArgument(self::ARG_TYPE);
    }

    /**
     * @param VersionParser $versionParser
     * @return Version
     */
    private function getVersion(VersionParser $versionParser) {
        return new Version(
            $versionParser->getMajor(),
            $versionParser->getMinor(),
            $versionParser->getPatch()
        );
    }

    /**
     * @param InputInterface $input
     * @return string
     */
    protected function getLastRev(InputInterface $input)
    {
        return $input->getOption(self::OPT_REV) ?: $this->getGitCommand()->getLastTag();
    }

    /**
     * @return Author
     */
    protected function getAuthor()
    {
        return (new AuthorLoader())
            ->load(new Author(), $this->getGitCommand());
    }

    /**
     * @param VersionExportable $version
     * @return Release
     */
    protected function getRelease(VersionExportable $version)
    {
        return new Release($version, new DateTime('now'), $this->getAuthor());
    }

}