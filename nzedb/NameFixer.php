<?php
namespace nzedb;

use app\models\Groups as Group;
use nzedb\db\DB;
use nzedb\processing\PostProcess;
use nzedb\utility\Text;

/**
 * Class NameFixer
 */
class NameFixer
{
	const PREDB_REGEX = '/([\w\(\)]+[\s\._-]([\w\(\)]+[\s\._-])+[\w\(\)]+-\w+)/';

	// Constants for name fixing status
	const PROC_NFO_NONE = 0;
	const PROC_NFO_DONE = 1;
	const PROC_FILES_NONE = 0;
	const PROC_FILES_DONE = 1;
	const PROC_PAR2_NONE = 0;
	const PROC_PAR2_DONE = 1;
	const PROC_UID_NONE = 0;
	const PROC_UID_DONE = 1;

	// Constants for overall rename status
	const IS_RENAMED_NONE = 0;
	const IS_RENAMED_DONE = 1;

	/**
	 * Has the current release found a new name?
	 * @var bool
	 */
	public $matched;

	/**
	 * How many releases have got a new name?
	 * @var int
	 */
	public $fixed;

	/**
	 * How many releases were checked.
	 * @var int
	 */
	public $checked;

	/**
	 * Whether or not the check has completed
	 * @var bool
	 */
	public $done;

	/**
	 * Whether or not to echo info to CLI
	 * @var bool
	 */
	public $echooutput;

	/**
	 * Total releases we are working on.
	 * @var int
	 */
	protected $_totalReleases;

	/**
	 * The cleaned filename we want to match
	 * @var string
	 */
	protected $_fileName;

	/**
	 * The release ID we are trying to rename
	 * @var int
	 */
	protected $relid;

	/**
	 * @var string
	 */
	protected $othercats;

	/**
	 * @var string
	 */
	protected $timeother;

	/**
	 * @var string
	 */
	protected $timeall;

	/**
	 * @var string
	 */
	protected $fullother;

	/**
	 * @var string
	 */
	protected $fullall;

	/**
	 * @var \nzedb\db\Settings
	 */
	public $pdo;

	/**
	 * @var \nzedb\ConsoleTools
	 */
	public $consoletools;

	/**
	 * @var \nzedb\Category
	 */
	public $category;

	/**
	 * @var \nzedb\utility\Text
	 */
	public $text;

	/**
	 * @var \nzedb\Groups
	 */
	public $_groups;

	/**
	 * @var \nzedb\SphinxSearch
	 */
	public $sphinx;

	/**
	 * @param array $options Class instances / Echo to cli.
	 */
	public function __construct(array $options = [])
	{
		$defaults = [
			'Echo'         => true,
			'Categorize'   => null,
			'ConsoleTools' => null,
			'Groups'       => null,
			'Misc'      => null,
			'Settings'     => null,
			'SphinxSearch' => null,
		];
		$options += $defaults;

		$this->echooutput = ($options['Echo'] && nZEDb_ECHOCLI);
		$this->relid = $this->fixed = $this->checked = 0;
		$this->pdo = ($options['Settings'] instanceof DB ? $options['Settings'] : new DB());
		$this->othercats = Category::getCategoryOthersGroup();
		$this->timeother = sprintf(' AND rel.adddate > (NOW() - INTERVAL 6 HOUR) AND rel.categories_id IN (%s) GROUP BY rel.id ORDER BY postdate DESC', $this->othercats);
		$this->timeall = ' AND rel.adddate > (NOW() - INTERVAL 6 HOUR) GROUP BY rel.id ORDER BY postdate DESC';
		$this->fullother = sprintf(' AND rel.categories_id IN (%s) GROUP BY rel.id', $this->othercats);
		$this->fullall = '';
		$this->_fileName = '';
		$this->done = $this->matched = false;
		$this->consoletools = ($options['ConsoleTools'] instanceof ConsoleTools ? $options['ConsoleTools'] : new ConsoleTools(['ColorCLI' => $this->pdo->log]));
		$this->category = ($options['Categorize'] instanceof Categorize ? $options['Categorize'] : new Categorize(['Settings' => $this->pdo]));
		$this->text = ($options['Misc'] instanceof Text ? $options['Misc'] : new Text());
		$this->_groups = ($options['Groups'] instanceof Groups ? $options['Groups'] : new Groups(['Settings' => $this->pdo]));
		$this->sphinx = ($options['SphinxSearch'] instanceof SphinxSearch ? $options['SphinxSearch'] : new SphinxSearch());
	}

	/**
	 * Attempts to fix release names using the NFO.
	 *
	 * @param int $time    1: 24 hours, 2: no time limit
	 * @param boolean $echo    1: change the name, anything else: preview of what could have been changed.
	 * @param int $cats    1: other categories, 2: all categories
	 * @param $nameStatus
	 * @param $show
	 */
	public function fixNamesWithNfo($time, $echo, $cats, $nameStatus, $show)
	{
		$this->_echoStartMessage($time, '.nfo files');
		$type = 'NFO, ';

		// Only select releases we haven't checked here before
		$preId = false;
		if ($cats === 3) {
			$query = sprintf('
					SELECT rel.id AS releases_id, rel.fromname
					FROM releases rel
					INNER JOIN release_nfos nfo ON (nfo.releases_id = rel.id)
					WHERE nzbstatus = %d
					AND predb_id = 0',
					NZB::NZB_ADDED
			);
			$cats = 2;
			$preId = true;
		} else {
			$query = sprintf('
					SELECT rel.id AS releases_id, rel.fromname
					FROM releases rel
					INNER JOIN release_nfos nfo ON (nfo.releases_id = rel.id)
					WHERE (rel.isrenamed = %d OR rel.categories_id = %d)
					AND proc_nfo = %d',
					self::IS_RENAMED_NONE,
					Category::OTHER_MISC,
					self::PROC_NFO_NONE
			);
		}

		$releases = $this->_getReleases($time, $cats, $query);

		if ($releases instanceof \Traversable && $releases !== false) {
			$total = $releases->rowCount();

			if ($total > 0) {
				$this->_totalReleases = $total;
				echo $this->pdo->log->primary(number_format($total) . ' releases to process.');

				foreach ($releases as $rel) {
					$releaseRow = $this->pdo->queryOneRow(
						sprintf('
							SELECT nfo.releases_id AS nfoid, rel.groups_id, rel.fromname, rel.categories_id, rel.name, rel.searchname,
								UNCOMPRESS(nfo) AS textstring, rel.id AS releases_id
							FROM releases rel
							INNER JOIN release_nfos nfo ON (nfo.releases_id = rel.id)
							WHERE rel.id = %d',
							$rel['releases_id']
						)
					);

					$this->checked++;

					// Ignore encrypted NFOs.
					if (preg_match('/^=newz\[NZB\]=\w+/', $releaseRow['textstring'])) {
						$this->_updateSingleColumn('proc_nfo', self::PROC_NFO_DONE, $rel['releases_id']);
						continue;
					}

					$this->done = $this->matched = false;
					$this->checkName($releaseRow, $echo, $type, $nameStatus, $show, $preId);
					$this->_echoRenamed($show);
				}
				$this->_echoFoundCount($echo, ' NFO\'s');
			} else {
				echo $this->pdo->log->info('Nothing to fix.');
			}
		}
	}

	/**
	 * Attempts to fix release names using the File name.
	 *
	 * @param int $time   1: 24 hours, 2: no time limit
	 * @param boolean $echo   1: change the name, anything else: preview of what could have been changed.
	 * @param int $cats   1: other categories, 2: all categories
	 * @param $nameStatus
	 * @param $show
	 */
	public function fixNamesWithFiles($time, $echo, $cats, $nameStatus, $show)
	{
		$this->_echoStartMessage($time, 'file names');
		$type = 'Filenames, ';

		$preId = false;
		if ($cats === 3) {
			$query = sprintf('
					SELECT rf.name AS textstring, rel.fromname, rel.categories_id, rel.name, rel.searchname, rel.groups_id,
						rf.releases_id AS fileid, rel.id AS releases_id
					FROM releases rel
					INNER JOIN release_files rf ON (rf.releases_id = rel.id)
					WHERE nzbstatus = %d
					AND predb_id = 0',
					NZB::NZB_ADDED
			);
			$cats = 2;
			$preId = true;
		} else {
			$query = sprintf('
					SELECT rf.name AS textstring, rel.fromname, rel.categories_id, rel.name, rel.searchname, rel.groups_id,
						rf.releases_id AS fileid, rel.id AS releases_id
					FROM releases rel
					INNER JOIN release_files rf ON (rf.releases_id = rel.id)
					WHERE (rel.isrenamed = %d OR rel.categories_id = %d)
					AND proc_files = %d',
					self::IS_RENAMED_NONE,
					Category::OTHER_MISC,
					self::PROC_FILES_NONE
			);
		}

		$releases = $this->_getReleases($time, $cats, $query);
		if ($releases instanceof \Traversable && $releases !== false) {

			$total = $releases->rowCount();
			if ($total > 0) {
				$this->_totalReleases = $total;
				echo $this->pdo->log->primary(number_format($total) . ' file names to process.');

				foreach ($releases as $release) {
					$this->done = $this->matched = false;
					$this->checkName($release, $echo, $type, $nameStatus, $show, $preId);
					$this->checked++;
					$this->_echoRenamed($show);
				}

				$this->_echoFoundCount($echo, ' files');
			} else {
				echo $this->pdo->log->info('Nothing to fix.');
			}
		}
	}

	/**
	 * Attempts to fix release names using the Par2 File.
	 *
	 * @param int $time   1: 24 hours, 2: no time limit
	 * @param int $echo   1: change the name, anything else: preview of what could have been changed.
	 * @param int $cats   1: other categories, 2: all categories
	 * @param $nameStatus
	 * @param $show
	 * @param NNTP $nntp
	 */
	public function fixNamesWithPar2($time, $echo, $cats, $nameStatus, $show, $nntp)
	{
		$this->_echoStartMessage($time, 'par2 files');

		if ($cats === 3) {
			$query = sprintf('
					SELECT rel.id AS releases_id, rel.fromname, rel.guid, rel.groups_id
					FROM releases rel
					WHERE nzbstatus = %d
					AND predb_id = 0',
					NZB::NZB_ADDED
			);
			$cats = 2;
		} else {
			$query = sprintf('
					SELECT rel.id AS releases_id, rel.fromname, rel.guid, rel.groups_id
					FROM releases rel
					WHERE (rel.isrenamed = %d OR rel.categories_id in (%d, %d, %d, %d, %d, %d, %d, %d, %d))
					AND proc_par2 = %d',
					self::IS_RENAMED_NONE,
					Category::BOOKS_UNKNOWN,
					Category::GAME_OTHER,
					Category::MOVIE_OTHER,
					Category::MUSIC_OTHER,
					Category::PC_PHONE_OTHER,
					Category::TV_OTHER,
					Category::OTHER_HASHED,
					Category::XXX_OTHER,
					Category::OTHER_MISC,
					self::PROC_PAR2_NONE
			);
		}

		$releases = $this->_getReleases($time, $cats, $query);

		if ($releases instanceof \Traversable && $releases !== false) {

			$total = $releases->rowCount();
			if ($total > 0) {
				$this->_totalReleases = $total;

				echo $this->pdo->log->primary(number_format($total) . ' releases to process.');
				$Nfo = new Nfo(['Echo' => $this->echooutput, 'Settings' => $this->pdo]);
				$nzbContents = new NZBContents(
					[
						'Echo'        => $this->echooutput,
						'NNTP'        => $nntp,
						'Nfo'         => $Nfo,
						'Settings'    => $this->pdo,
						'PostProcess' => new PostProcess(['Settings' => $this->pdo, 'Nfo' => $Nfo])
					]
				);

				foreach ($releases as $release) {
					if (($nzbContents->checkPAR2($release['guid'], $release['releases_id'], $release['groups_id'], $nameStatus, $show)) === true) {
						$this->fixed++;
					}

					$this->checked++;
					$this->_echoRenamed($show);
				}
				$this->_echoFoundCount($echo, ' files');
			} else {
				echo $this->pdo->log->alternate('Nothing to fix.');
			}
		}
	}

	/**
	 * Attempts to fix release names using the mediainfo xml Unique_ID.
	 *
	 * @param int     $time 1: 24 hours, 2: no time limit
	 * @param boolean $echo 1: change the name, anything else: preview of what could have been changed.
	 * @param int     $cats 1: other categories, 2: all categories
	 * @param         $nameStatus
	 * @param         $show
	 */
	public function fixNamesWithMedia($time, $echo, $cats, $nameStatus, $show)
	{
		$type = 'UID, ';

		$this->_echoStartMessage($time, 'mediainfo Unique_IDs');

		// Re-check all releases we haven't matched to a PreDB
		if ($cats === 3) {
			$query = sprintf('
				SELECT
					rel.id AS releases_id, rel.fromname, rel.size AS relsize, rel.groups_id, rel.categories_id,
					rel.name, rel.name AS textstring, rel.predb_id, rel.searchname,
					HEX(ru.uniqueid) AS uid
				FROM releases rel
				LEFT JOIN release_unique ru ON ru.releases_id = rel.id
				WHERE ru.releases_id IS NOT NULL
				AND rel.nzbstatus = %d
				AND rel.predb_id = 0',
				NZB::NZB_ADDED
			);
			$cats = 2;
		// Otherwise check only releases we haven't renamed and checked uid before in Misc categories
		} else {
			$query = sprintf('
				SELECT
					rel.id AS releases_id, rel.fromname, rel.size AS relsize, rel.groups_id, rel.categories_id,
					rel.name, rel.name AS textstring, rel.predb_id, rel.searchname,
					HEX(ru.uniqueid) AS uid
				FROM releases rel
				LEFT JOIN release_unique ru ON ru.releases_id = rel.id
				WHERE ru.releases_id IS NOT NULL
				AND rel.nzbstatus = %d
				AND rel.isrenamed = %d
				AND rel.categories_id IN (%d, %d)
				AND rel.proc_uid = %d',
				NZB::NZB_ADDED,
				self::IS_RENAMED_NONE,
				Category::OTHER_MISC,
				Category::OTHER_HASHED,
				self::PROC_UID_NONE
			);
		}

		$releases = $this->_getReleases($time, $cats, $query);
		if ($releases instanceof \Traversable && $releases !== false) {
			$total = $releases->rowCount();
			if ($total > 0) {
				$this->_totalReleases = $total;
				echo $this->pdo->log->primary(number_format($total) . ' unique ids to process.');
				foreach ($releases as $rel) {
					$this->checked++;
					$this->done = $this->matched = false;
					$this->uidCheck($rel, $echo, $type, $nameStatus, $show);
					$this->_echoRenamed($show);
				}
				$this->_echoFoundCount($echo, ' UID\'s');
			} else {
				echo $this->pdo->log->info('Nothing to fix.');
			}
		}
	}

	/**
	 * @param int    $time  1: 24 hours, 2: no time limit
	 * @param int    $cats  1: other categories, 2: all categories
	 * @param string $query Query to execute.
	 *
	 * @param string $limit limit defined by maxperrun
	 *
	 * @return bool|\PDOStatement False on failure, PDOStatement with query results on success.
	 */
	protected function _getReleases($time, $cats, $query, $limit = '')
	{
		$releases = false;

		// Collapse white space because it looks awful on the echo
		$query = Text::collapseWhiteSpace($query);

		$queryLimit = ($limit === '') ? '' : ' LIMIT ' . $limit;
		// 24 hours, other cats
		if ($time == 1 && $cats == 1) {
			echo $this->pdo->log->header($query . $this->timeother . $queryLimit . ";\n");
			$releases = $this->pdo->queryDirect($query . $this->timeother . $queryLimit);
		} // 24 hours, all cats
		else if ($time == 1 && $cats == 2) {
			echo $this->pdo->log->header($query . $this->timeall . $queryLimit . ";\n");
			$releases = $this->pdo->queryDirect($query . $this->timeall . $queryLimit);
		} //other cats
		else if ($time == 2 && $cats == 1) {
			echo $this->pdo->log->header($query . $this->fullother . $queryLimit . ";\n");
			$releases = $this->pdo->queryDirect($query . $this->fullother . $queryLimit);
		}
		// all cats
		else if ($time == 2 && $cats == 2) {
			echo $this->pdo->log->header($query . $this->fullall . $queryLimit . ";\n");
			$releases = $this->pdo->queryDirect($query . $this->fullall . $queryLimit);
		}
		return $releases;
	}

	/**
	 * Echo the amount of releases that found a new name.
	 *
	 * @param int    $echo 1: change the name, anything else: preview of what could have been changed.
	 * @param string $type The function type that found the name.
	 */
	protected function _echoFoundCount($echo, $type)
	{
		if ($echo == 1) {
			echo $this->pdo->log->header(
				PHP_EOL .
				number_format($this->fixed) .
				' releases have had their names changed out of: ' .
				number_format($this->checked) .
				$type . '.'
			);
		} else {
			echo $this->pdo->log->header(
				PHP_EOL .
				number_format($this->fixed) .
				' releases could have their names changed. ' .
				number_format($this->checked) .
				$type . ' were checked.'
			);
		}
	}

	/**
	 * @param int    $time 1: 24 hours, 2: no time limit
	 * @param string $type The function type.
	 */
	protected function _echoStartMessage($time, $type)
	{
		echo $this->pdo->log->header(
			sprintf(
				'Fixing search names %s using %s.',
				($time == 1 ? 'in the past 6 hours' : 'since the beginning'),
				$type
			)
		);

	}

	/**
	 * @param int $show
	 */
	protected function _echoRenamed($show)
	{
		if ($this->checked % 500 == 0 && $show === 1) {
			echo $this->pdo->log->alternate(PHP_EOL . number_format($this->checked) . ' files processed.' . PHP_EOL);
		}

		if ($show === 2) {
			$this->consoletools->overWritePrimary(
				'Renamed Releases: [' .
				number_format($this->fixed) .
				'] ' .
				$this->consoletools->percentString($this->checked, $this->_totalReleases)
			);
		}
	}

	/**
	 * Update the release with the new information.
	 *
	 * @param array   $release
	 * @param string  $name
	 * @param string  $method
	 * @param boolean $echo
	 * @param string  $type
	 * @param int     $nameStatus
	 * @param int     $show
	 * @param int     $preId
	 */
	public function updateRelease($release, $name, $method, $echo, $type, $nameStatus, $show, $preId = 0)
	{
		$release["releases_id"] = (isset($release['releases_id']) ? $release['releases_id'] : $release["releaseid"]);
		if ($this->relid !== $release['releases_id']) {
			$releaseCleaning = new ReleaseCleaning($this->pdo);
			$newName = $releaseCleaning->fixerCleaner($name);
			if (strtolower($newName) != strtolower($release["searchname"])) {
				$this->matched = true;
				$this->relid = $release['releases_id'];

				$determinedCategory = $this->category->determineCategory($release['groups_id'], $newName, !empty($release['fromname']) ? $release['fromname'] : '');

				if ($type === "PAR2, ") {
					$newName = ucwords($newName);
					if (preg_match('/(.+?)\.[a-z0-9]{2,3}(PAR2)?$/i', $name, $match)) {
						$newName = $match[1];
					}
				}

				$this->fixed++;

				if(!empty($release['fromname']) && (preg_match('/oz@lot[.]com/i', $release['fromname']) || preg_match('/anon@y[.]com/i', $release['fromname']))) {
					$newName = preg_replace('/(KTR|GUSH|BIUK|WEIRD)$/', 'SDCLiP', $newName);
				}
				$newName = explode("\\", $newName);
				$newName = preg_replace(['/^[-=_\.:\s]+/', '/[-=_\.:\s]+$/'], '', $newName[0]);

				if ($this->echooutput === true && $show === 1) {
					$groupName = Group::getNameByID($release['groups_id']);
					$oldCatName = $this->category->getNameByID($release['categories_id']);
					$newCatName = $this->category->getNameByID($determinedCategory);

					if ($type === "PAR2, ") {
						echo PHP_EOL;
					}

					echo
						$this->pdo->log->headerOver("\nNew name:  ") .
						$this->pdo->log->primary(substr($newName, 0, 255)) .
						$this->pdo->log->headerOver("Old name:  ") .
						$this->pdo->log->primary($release["searchname"]) .
						$this->pdo->log->headerOver("Use name:  ") .
						$this->pdo->log->primary($release["name"]) .
						$this->pdo->log->headerOver("New cat:   ") .
						$this->pdo->log->primary($newCatName) .
						$this->pdo->log->headerOver("Old cat:   ") .
						$this->pdo->log->primary($oldCatName) .
						$this->pdo->log->headerOver("Group:     ") .
						$this->pdo->log->primary($groupName) .
						$this->pdo->log->headerOver("Method:    ") .
						$this->pdo->log->primary($type . $method) .
						$this->pdo->log->headerOver("ReleaseID: ") .
						$this->pdo->log->primary($release["releases_id"]);
					if (isset($release['filename']) && $release['filename'] != "") {
						echo
							$this->pdo->log->headerOver("Filename:  ") .
							$this->pdo->log->primary($release["filename"]);
					}

					if ($type !== "PAR2, ") {
						echo "\n";
					}
				}

				$newTitle = $this->pdo->escapeString(substr($newName, 0, 255));

				if ($echo == true) {
					if ($nameStatus == 1) {
						$status = '';
						switch ($type) {
							case "NFO, ":
								$status = "isrenamed = 1, iscategorized = 1, proc_nfo = 1,";
								break;
							case "PAR2, ":
								$status = "isrenamed = 1, iscategorized = 1, proc_par2 = 1,";
								break;
							case "Filenames, ":
							case "file matched source: ":
								$status = "isrenamed = 1, iscategorized = 1, proc_files = 1,";
								break;
							case "SHA1, ":
							case "MD5, ":
								$status = "isrenamed = 1, iscategorized = 1, dehashstatus = 1,";
								break;
							case "PreDB FT Exact, ":
								$status = "isrenamed = 1, iscategorized = 1,";
								break;
							case "sorter ":
								$status = "isrenamed = 1, iscategorized = 1, proc_sorter = 1,";
								break;
							case "UID, ":
								$status = "isrenamed = 1, iscategorized = 1, proc_uid = 1,";
								break;
						}
						$this->pdo->queryExec(
							sprintf('
								UPDATE releases
								SET videos_id = 0, tv_episodes_id = 0, imdbid = NULL, musicinfo_id = NULL,
									consoleinfo_id = NULL, bookinfo_id = NULL, anidbid = NULL, predb_id = %d,
									searchname = %s, %s categories_id = %d
								WHERE id = %d',
								$preId,
								$newTitle,
								$status,
								$determinedCategory,
								$release['releases_id']
							)
						);
						$this->sphinx->updateRelease($release['releases_id'], $this->pdo);
					} else {
						$newTitle = $this->pdo->escapeString(substr($newName, 0, 255));
						$this->pdo->queryExec(
							sprintf('
								UPDATE releases
								SET videos_id = 0, tv_episodes_id = 0, imdbid = NULL, musicinfo_id = NULL,
									consoleinfo_id = NULL, bookinfo_id = NULL, anidbid = NULL, predb_id = %d,
									searchname = %s, iscategorized = 1, categories_id = %d
								WHERE id = %d',
								$preId,
								$newTitle,
								$determinedCategory,
								$release['releases_id']
							)
						);
						$this->sphinx->updateRelease($release['releases_id'], $this->pdo);
					}
				}
			}
		}
		$this->done = true;
	}

	/**
	 * Echo a updated release name to CLI.
	 *
	 * @param array $data
	 *        array(
	 *              'new_name'     => (string) The new release search name.
	 *              'old_name'     => (string) The old release search name.
	 *              'new_category' => (string) The new category name or ID for the release.
	 *              'old_category' => (string) The old category name or ID for the release.
	 *              'group'        => (string) The group name or ID of the release.
	 *              'release_id'   => (int)    The ID of the release.
	 *              'method'       => (string) The method used to rename the release.
	 *        )
	 *
	 * @access public
	 * @static
	 * @void
	 */
	public static function echoChangedReleaseName(array $data =
		[
			'new_name'     => '',
			'old_name'     => '',
			'new_category' => '',
			'old_category' => '',
			'group'        => '',
			'release_id'   => 0,
			'method'       => ''
		]
	) {
		echo
			PHP_EOL .
			ColorCLI::headerOver('New name:     ') . ColorCLI::primaryOver($data['new_name']) . PHP_EOL .
			ColorCLI::headerOver('Old name:     ') . ColorCLI::primaryOver($data['old_name']) . PHP_EOL .
			ColorCLI::headerOver('New category: ') . ColorCLI::primaryOver($data['new_category']) . PHP_EOL .
			ColorCLI::headerOver('Old category: ') . ColorCLI::primaryOver($data['old_category']) . PHP_EOL .
			ColorCLI::headerOver('Group:        ') . ColorCLI::primaryOver($data['group']) . PHP_EOL .
			ColorCLI::headerOver('Release ID:   ') . ColorCLI::primaryOver($data['release_id']) . PHP_EOL .
			ColorCLI::headerOver('Method:       ') . ColorCLI::primaryOver($data['method']) . PHP_EOL;
	}

	// Match a PreDB title to a release name or searchname using an exact full-text match
	public function matchPredbFT($pre, $echo, $namestatus, $echooutput, $show)
	{
		$matching = $total = 0;

		$join = $this->_preFTsearchQuery($pre['title']);

		if ($join === '') {

			return $matching;
		}

		//Find release matches with fulltext and then identify exact matches with cleaned LIKE string
		$res = $this->pdo->queryDirect(
			sprintf("
				SELECT r.id AS releases_id, r.name, r.fromname, r.searchname,
					r.groups_id, r.categories_id
				FROM releases r
				%1\$s
				AND (r.name %2\$s OR r.searchname %2\$s)
				AND r.predb_id = 0
				LIMIT 21",
				$join,
				$this->pdo->likeString($pre['title'], true, true)
			)
		);

		if ($res !== false) {
			$total = $res->rowCount();
		}

		// Run if row count is positive, but do not run if row count exceeds 10 (as this is likely a failed title match)
		if ($total > 0 && $total <= 15 && $res instanceof \Traversable) {
			foreach ($res as $row) {
				if ($pre['title'] !== $row['searchname']) {
					$this->updateRelease($row, $pre['title'], $method = "Title Match source: " . $pre['source'], $echo, "PreDB FT Exact, ", $namestatus, $show, $pre['predb_id']);
					$matching++;
				} else {
					$this->_updateSingleColumn('predb_id', $pre['predb_id'], $row['releases_id']);
				}
			}
		} elseif ($total >= 16) {
			$matching = -1;
		}
		return $matching;
	}

	protected function _preFTsearchQuery($preTitle)
	{
		$join = '';

		if (strlen($preTitle) >= 15 && preg_match(self::PREDB_REGEX, $preTitle)) {
			switch (nZEDb_RELEASE_SEARCH_TYPE) {
				case ReleaseSearch::SPHINX:
					$titlematch = SphinxSearch::escapeString($preTitle);
					$join .= sprintf(
						'INNER JOIN releases_se rse ON rse.id = r.id
						WHERE rse.query = "@(name,searchname,filename) %s;mode=extended"',
						$titlematch
					);
					break;
				case ReleaseSearch::FULLTEXT:
					//Remove all non-printable chars from PreDB title
					preg_match_all('#[a-zA-Z0-9]{3,}#', $preTitle, $matches, PREG_PATTERN_ORDER);
					$titlematch = '+' . implode(' +', $matches[0]);
					$join .= sprintf(
						"INNER JOIN release_search_data rs ON rs.releases_id = r.id
						WHERE
							(MATCH (rs.name) AGAINST ('%1\$s' IN BOOLEAN MODE)
							OR MATCH (rs.searchname) AGAINST ('%1\$s' IN BOOLEAN MODE))",
						$titlematch
					);
					break;
				case ReleaseSearch::LIKE:
					// Do not add a JOIN for FT, let the query run in LIKE mode only (slow)
					$join .= 'WHERE 1=1 ';
					break;
			}
		}
		return $join;
	}

	/**
	 * Retrieves releases and their file names to attempt PreDB matches
	 * Runs in a limited mode based on arguments passed or a full mode broken into chunks of entire DB
	 *
	 * @param array $args The CLI script arguments
	 */
	public function getPreFileNames(array $args = array())
	{
		$n = PHP_EOL;

		$show = (isset($args[2]) && $args[2] === 'show') ? 1 : 0;

		if (isset($args[1]) && is_numeric($args[1])) {
			$limit = "LIMIT " . $args[1];
			$orderby = 'ORDER BY r.id DESC';
		} else {
			$maxrelid = 0;
			$orderby = 'ORDER BY r.id ASC';
			$limit = "LIMIT 1000000";
		}

		echo $this->pdo->log->header("\nMatch PreFiles (${args[1]}) Started at " . date('g:i:s'));
		echo $this->pdo->log->primary("Matching predb filename to cleaned release_files.name.\n");

		do {
			$counter = $counted = 0;
			$timestart = time();

			$query = $this->pdo->queryDirect(
				sprintf("
					SELECT r.id AS releases_id, r.name, r.fromname, r.searchname,
						r.groups_id, r.categories_id,
						GROUP_CONCAT(rf.name ORDER BY LENGTH(rf.name) DESC SEPARATOR '||') AS filename
					FROM releases r
					INNER JOIN release_files rf ON r.id = rf.releases_id
					AND rf.name IS NOT NULL
					WHERE %s
					r.predb_id = 0
					GROUP BY r.id
					%s %s",
					(isset($maxrelid) && $maxrelid > 0 ? "r.id > {$maxrelid} AND" : ''),
					$orderby,
					$limit
				)
			);

			if ($query !== false) {
				$total = $query->rowCount();

				if ($total > 0 && $query instanceof \Traversable) {
					echo $this->pdo->log->header($n . number_format($total) . ' releases to process.');

					foreach ($query as $row) {
						$success = $this->matchPredbFiles($row, 1, 1, $show);
						if ($success === 1) {
							$counted++;
						}
						if ($show === 0) {
							$this->consoletools->overWritePrimary("Renamed Releases: [" . number_format($counted) . "] " . $this->consoletools->percentString(++$counter, $total));
						}
						if (isset($maxrelid) && $row['releases_id'] > $maxrelid) {
							$maxrelid = $row['releases_id'];
						}
					}
					echo $this->pdo->log->header($n . "Renamed " . number_format($counted) . " releases in " . $this->consoletools->convertTime(time() - $timestart) . ".");
				} else {
					echo $this->pdo->log->info($n . "Nothing to do.");
					break;
				}
			} else {
				break;
			}
		} while (isset($maxrelid));
	}

	/**
	 * Match a release filename to a PreDB filename or title.
	 *
	 * @param         $release
	 * @param boolean $echo
	 * @param integer $namestatus
	 * @param integer $show
	 *
	 * @return int
	 */
	public function matchPredbFiles($release, $echo, $namestatus, $show)
	{
		$matching = 0;
		$pre = false;

		$matchPre = function(string $fileName) {
			$success = preg_match('/(\d{2}\.\d{2}\.\d{2})+[\w.-]+[\w]$/i', $fileName, $match);

			return $success === 1 ? $match : false;
		};

		foreach (explode('||', $release['filename']) as $key => $fileName) {
			$this->_fileName = '';
			$this->_cleanMatchFiles();

			$match1 = $matchPre($fileName);
			if (\is_array($match1)) {
				$result = $this->pdo->queryOneRow(sprintf("SELECT filename AS filename FROM predb WHERE MATCH(filename) AGAINST ('$match1[0]' IN BOOLEAN MODE)"));

				if (!empty($result['filename'])) {
					$match2 = $matchPre($result['filename']);
					if (\is_array($match2) && $match1[0] === $match2[0]) {
						\similar_text($match1[1], $match2[1], $percentage);
						if ($percentage >= 93) {
							$this->_fileName = $result['filename'];
						}
					}
				}
			}

			if ($this->_fileName !== '') {
				$pre = $this->pdo->queryOneRow(
					sprintf('
					SELECT id AS predb_id, title, source
					FROM predb
					WHERE filename = %s
					OR title = %1$s',
					$this->pdo->escapeString($this->_fileName)
					)
				);
			}

			if ($pre !== null && $pre !== false) {
				$release['filename'] = $this->_fileName;
				if ($pre['title'] !== $release['searchname']) {
					$this->updateRelease($release, $pre['title'], $method = 'file matched source: ' . $pre['source'], $echo, 'PreDB file match, ', $namestatus, $show, $pre['predb_id']);
				} else {
					$this->_updateSingleColumn('predb_id', $pre['predb_id'], $release['releases_id']);
				}
				$matching++;
				break;
			}
		}

		return $matching;
	}

	/**
	 * Cleans file names for PreDB Match
	 *
	 * @return string
	 */
	protected function _cleanMatchFiles()
	{

		// first strip all non-printing chars  from filename
		$this->_fileName = $this->text->stripNonPrintingChars($this->_fileName);

		if (strlen($this->_fileName) !== false && strlen($this->_fileName) > 0 && strpos($this->_fileName, '.') !== 0) {
			switch (true) {

				case strpos($this->_fileName, '.') !== false:
					//some filenames start with a period that ends up creating bad matches so we don't process them
					$this->_fileName = $this->text->cutStringUsingLast('.', $this->_fileName, "left", false);
					continue;

				//if filename has a .part001, send it back to the function to cut the next period
				case preg_match('/\.part\d+$/', $this->_fileName):
					$this->_fileName = $this->text->cutStringUsingLast('.', $this->_fileName, "left", false);
					continue;

				//if filename has a .vol001, send it back to the function to cut the next period
				case preg_match('/\.vol\d+(\+\d+)?$/', $this->_fileName):
					$this->_fileName = $this->text->cutStringUsingLast('.', $this->_fileName, "left", false);
					continue;

				//if filename contains a slash, cut the string and keep string to the right of the last slash to remove dir
				case strpos($this->_fileName, '\\') !== false:
					$this->_fileName = $this->text->cutStringUsingLast('\\', $this->_fileName, "right", false);
					continue;

				// A lot of obscured releases have one NFO file properly named with a track number (Audio) at the front of it
				// This will strip out the track and match it to its pre title
				case preg_match('/^\d{2}-/', $this->_fileName):
					$this->_fileName = preg_replace('/^\d{2}-/', '', $this->_fileName);
			}
		}
	}

	/**
	 * Match a Hash from the predb to a release.
	 *
	 * @param string $hash
	 * @param $release
	 * @param bool $echo
	 * @param $namestatus
	 * @param int $show
	 *
	 * @return int
	 */
	public function matchPredbHash($hash, $release, $echo, $namestatus, $echooutput, $show)
	{
		$pdo = $this->pdo;
		$matching = 0;
		$this->matched = false;

		// Determine MD5 or SHA1
		if (strlen($hash) === 40) {
			$hashtype = "SHA1, ";
		} else {
			$hashtype = "MD5, ";
		}

		$row = $pdo->queryOneRow(
					sprintf("
						SELECT p.id AS predb_id, p.title, p.source
						FROM predb p INNER JOIN predb_hashes h ON h.predb_id = p.id
						WHERE h.hash = UNHEX(%s)
						LIMIT 1",
						$pdo->escapeString($hash)
					)
		);

		if ($row !== false) {
			if ($row["title"] !== $release["searchname"]) {
					$this->updateRelease($release, $row["title"], $method = "predb hash release name: " . $row["source"], $echo, $hashtype, $namestatus, $show, $row['predb_id']);
					$matching++;
			}
		} else {
			$this->_updateSingleColumn('dehashstatus', $release['dehashstatus'] - 1, $release['releases_id']);
		}
		return $matching;
	}

	/**
	 * Check the array using regex for a clean name.
	 *
	 * @param         $release
	 * @param boolean $echo
	 * @param string  $type
	 * @param         $namestatus
	 * @param         $show
	 * @param boolean $preid
	 *
	 * @return boolean
	 */
	public function checkName($release, $echo, $type, $namestatus, $show, $preid = false)
	{
		// Get pre style name from releases.name
		if (preg_match_all(self::PREDB_REGEX, $release['textstring'], $matches) && !preg_match('/Source\s\:/i', $release['textstring'])) {
			foreach ($matches as $match) {
				foreach ($match as $val) {
					$title = $this->pdo->queryOneRow("SELECT title, id from predb WHERE title = " . $this->pdo->escapeString(trim($val)));
					if ($title !== false) {
						$this->updateRelease($release, $title['title'], $method = "preDB: Match", $echo, $type, $namestatus, $show, $title['id']);
						$preid = true;
					}
				}
			}
		}

		// if only processing for PreDB match skip to return
		if ($preid !== true) {

			switch ($type) {
				case "PAR2, ":
					$this->fileCheck($release, $echo, $type, $namestatus, $show);
					break;
				case "UID, ":
					$this->uidCheck($release, $echo, $type, $namestatus, $show);
					break;
				case "NFO, ":
					$this->nfoCheckTV($release, $echo, $type, $namestatus, $show);
					$this->nfoCheckMov($release, $echo, $type, $namestatus, $show);
					$this->nfoCheckMus($release, $echo, $type, $namestatus, $show);
					$this->nfoCheckTY($release, $echo, $type, $namestatus, $show);
					$this->nfoCheckG($release, $echo, $type, $namestatus, $show);
					continue;
				case "Filenames, ":
					$this->fileCheck($release, $echo, $type, $namestatus, $show);
					continue;
				default:
					$this->tvCheck($release, $echo, $type, $namestatus, $show);
					$this->movieCheck($release, $echo, $type, $namestatus, $show);
					$this->gameCheck($release, $echo, $type, $namestatus, $show);
					$this->appCheck($release, $echo, $type, $namestatus, $show);
			}

			// set NameFixer process flags after run
			if ($namestatus == 1 && $this->matched === false) {
				switch ($type) {
					case  "NFO, ":
						$this->_updateSingleColumn('proc_nfo', self::PROC_NFO_DONE, $release['releases_id']);
						break;
					case "Filenames, ":
						$this->_updateSingleColumn('proc_files', self::PROC_FILES_DONE, $release['releases_id']);
						break;
					case "PAR2, ":
						$this->_updateSingleColumn('proc_par2', self::PROC_PAR2_DONE, $release['releases_id']);
						break;
					case "UID, ":
						$this->_updateSingleColumn('proc_uid', self::PROC_UID_DONE, $release['releases_id']);
						break;
				}
			}
		}

		return $this->matched;
	}

	/** This function updates a single variable column in releases
	 *  The first parameter is the column to update, the second is the value
	 *  The final parameter is the ID of the release to update
	 *
	 * @param string  $column
	 * @param integer $status
	 * @param integer $id
	 */
	public function _updateSingleColumn($column = '', $status = 0, $id = 0)
	{
		if ($column !== '' && $id !== 0) {
			$this->pdo->queryExec(
						sprintf('
							UPDATE releases
							SET %s = %s
							WHERE id = %d',
							$column,
							(is_numeric($status) ? $status : $this->pdo->escapeString($status)),
							$id
						)
			);
		}
	}

	/**
	 * Look for a TV name.
	 *
	 * @param $release
	 * @param boolean $echo
	 * @param string  $type
	 * @param $namestatus
	 * @param $show
	 */
	public function tvCheck($release, $echo, $type, $namestatus, $show)
	{
		$result = [];

		if ($this->done === false && $this->relid !== $release["releases_id"]) {

			if (preg_match('/\w[-\w.\',;& ]+((s\d{1,2}[._ -]?[bde]\d{1,2})|(?<!\d)[S|]\d{1,2}[E|x]\d{1,}(?!\d)|ep[._ -]?\d{2})[-\w.\',;.()]+(BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -][-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
				$this->updateRelease($release, $result["0"], $method = "tvCheck: Title.SxxExx.Text.source.group", $echo, $type, $namestatus, $show);
			} else if (preg_match('/\w[-\w.\',;& ]+((s\d{1,2}[._ -]?[bde]\d{1,2})|\d{1,2}x\d{2}|ep[._ -]?\d{2})[-\w.\',;& ]+((19|20)\d\d)[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
				$this->updateRelease($release, $result["0"], $method = "tvCheck: Title.SxxExx.Text.year.group", $echo, $type, $namestatus, $show);
			} else if (preg_match('/\w[-\w.\',;& ]+((s\d{1,2}[._ -]?[bde]\d{1,2})|\d{1,2}x\d{2}|ep[._ -]?\d{2})[-\w.\',;& ]+(480|720|1080)[ip][._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
				$this->updateRelease($release, $result["0"], $method = "tvCheck: Title.SxxExx.Text.resolution.source.vcodec.group", $echo, $type, $namestatus, $show);
			} else if (preg_match('/\w[-\w.\',;& ]+((s\d{1,2}[._ -]?[bde]\d{1,2})|\d{1,2}x\d{2}|ep[._ -]?\d{2})[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
				$this->updateRelease($release, $result["0"], $method = "tvCheck: Title.SxxExx.source.vcodec.group", $echo, $type, $namestatus, $show);
			} else if (preg_match('/\w[-\w.\',;& ]+((s\d{1,2}[._ -]?[bde]\d{1,2})|\d{1,2}x\d{2}|ep[._ -]?\d{2})[._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?|Dolby( ?TrueHD)?|MP3|TrueHD)[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](480|720|1080)[ip][._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
				$this->updateRelease($release, $result["0"], $method = "tvCheck: Title.SxxExx.acodec.source.res.vcodec.group", $echo, $type, $namestatus, $show);
			} else if (preg_match('/\w[-\w.\',;& ]+((s\d{1,2}[._ -]?[bde]\d{1,2})|\d{1,2}x\d{2}|ep[._ -]?\d{2})[-\w.\',;& ]+((19|20)\d\d)[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
				$this->updateRelease($release, $result["0"], $method = "tvCheck: Title.SxxExx.resolution.source.vcodec.group", $echo, $type, $namestatus, $show);
			} else if (preg_match('/\w[-\w.\',;& ]+((19|20)\d\d)[._ -]((s\d{1,2}[._ -]?[bde]\d{1,2})|\d{1,2}x\d{2}|ep[._ -]?\d{2})[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
				$this->updateRelease($release, $result["0"], $method = "tvCheck: Title.year.###(season/episode).source.group", $echo, $type, $namestatus, $show);
			} else if (preg_match('/\w(19|20)\d\d[._ -]\d{2}[._ -]\d{2}[._ -](IndyCar|NBA|NCW(T|Y)S|NNS|NSCS?)([._ -](19|20)\d\d)?[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
				$this->updateRelease($release, $result["0"], $method = "tvCheck: Sports", $echo, $type, $namestatus, $show);
			}
		}
	}

	/**
	 * Look for a movie name.
	 *
	 * @param $release
	 * @param boolean $echo
	 * @param string  $type
	 * @param $namestatus
	 * @param $show
	 */
	public function movieCheck($release, $echo, $type, $namestatus, $show)
	{
		$result = [];

		if ($this->done === false && $this->relid !== $release["releases_id"]) {

			if (preg_match('/\w[-\w.\',;& ]+((19|20)\d\d)[-\w.\',;& ]+(480|720|1080)[ip][._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
				$this->updateRelease($release, $result["0"], $method = "movieCheck: Title.year.Text.res.vcod.group", $echo, $type, $namestatus, $show);
			} else if (preg_match('/\w[-\w.\',;& ]+((19|20)\d\d)[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[._ -](480|720|1080)[ip][-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
				$this->updateRelease($release, $result["0"], $method = "movieCheck: Title.year.source.vcodec.res.group", $echo, $type, $namestatus, $show);
			} else if (preg_match('/\w[-\w.\',;& ]+((19|20)\d\d)[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?|Dolby( ?TrueHD)?|MP3|TrueHD)[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
				$this->updateRelease($release, $result["0"], $method = "movieCheck: Title.year.source.vcodec.acodec.group", $echo, $type, $namestatus, $show);
			} else if (preg_match('/\w[-\w.\',;& ]+(Brazilian|Chinese|Croatian|Danish|Deutsch|Dutch|Estonian|English|Finnish|Flemish|Francais|French|German|Greek|Hebrew|Icelandic|Italian|Japenese|Japan|Japanese|Korean|Latin|Nordic|Norwegian|Polish|Portuguese|Russian|Serbian|Slovenian|Swedish|Spanisch|Spanish|Thai|Turkish)[._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?|Dolby( ?TrueHD)?|MP3|TrueHD)[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
				$this->updateRelease($release, $result["0"], $method = "movieCheck: Title.year.language.acodec.source.vcodec.group", $echo, $type, $namestatus, $show);
			} else if (preg_match('/\w[-\w.\',;& ]+((19|20)\d\d)[._ -](480|720|1080)[ip][._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?|Dolby( ?TrueHD)?|MP3|TrueHD)[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
				$this->updateRelease($release, $result["0"], $method = "movieCheck: Title.year.resolution.source.acodec.vcodec.group", $echo, $type, $namestatus, $show);
			} else if (preg_match('/\w[-\w.\',;& ]+((19|20)\d\d)[._ -](480|720|1080)[ip][._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
				$this->updateRelease($release, $result["0"], $method = "movieCheck: Title.year.resolution.source.vcodec.group", $echo, $type, $namestatus, $show);
			} else if (preg_match('/\w[-\w.\',;& ]+((19|20)\d\d)[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](480|720|1080)[ip][._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?|Dolby( ?TrueHD)?|MP3|TrueHD)[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
				$this->updateRelease($release, $result["0"], $method = "movieCheck: Title.year.source.resolution.acodec.vcodec.group", $echo, $type, $namestatus, $show);
			} else if (preg_match('/\w[-\w.\',;& ]+((19|20)\d\d)[._ -](480|720|1080)[ip][._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?|Dolby( ?TrueHD)?|MP3|TrueHD)[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
				$this->updateRelease($release, $result["0"], $method = "movieCheck: Title.year.resolution.acodec.vcodec.group", $echo, $type, $namestatus, $show);
			} else if (preg_match('/[-\w.\',;& ]+((19|20)\d\d)[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BR(RIP)?|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](480|720|1080)[ip][._ -][-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
				$this->updateRelease($release, $result["0"], $method = "movieCheck: Title.year.source.res.group", $echo, $type, $namestatus, $show);
			} else if (preg_match('/\w[-\w.\',;& ]+((19|20)\d\d)[._ -][-\w.\',;& ]+[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BR(RIP)?|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
				$this->updateRelease($release, $result["0"], $method = "movieCheck: Title.year.eptitle.source.vcodec.group", $echo, $type, $namestatus, $show);
			} else if (preg_match('/\w[-\w.\',;& ]+(480|720|1080)[ip][._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?|Dolby( ?TrueHD)?|MP3|TrueHD)[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
				$this->updateRelease($release, $result["0"], $method = "movieCheck: Title.resolution.source.acodec.vcodec.group", $echo, $type, $namestatus, $show);
			} else if (preg_match('/\w[-\w.\',;& ]+(480|720|1080)[ip][._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?|Dolby( ?TrueHD)?|MP3|TrueHD)[-\w.\',;& ]+(BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -]((19|20)\d\d)[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
				$this->updateRelease($release, $result["0"], $method = "movieCheck: Title.resolution.acodec.eptitle.source.year.group", $echo, $type, $namestatus, $show);
			} else if (preg_match('/\w[-\w.\',;& ]+(Brazilian|Chinese|Croatian|Danish|Deutsch|Dutch|Estonian|English|Finnish|Flemish|Francais|French|German|Greek|Hebrew|Icelandic|Italian|Japenese|Japan|Japanese|Korean|Latin|Nordic|Norwegian|Polish|Portuguese|Russian|Serbian|Slovenian|Swedish|Spanisch|Spanish|Thai|Turkish)[._ -]((19|20)\d\d)[._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?|Dolby( ?TrueHD)?|MP3|TrueHD)[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
				$this->updateRelease($release, $result["0"], $method = "movieCheck: Title.language.year.acodec.src", $echo, $type, $namestatus, $show);
			}
		}
	}

	/**
	 * Look for a game name.
	 *
	 * @param $release
	 * @param boolean $echo
	 * @param string $type
	 * @param $namestatus
	 * @param $show
	 */
	public function gameCheck($release, $echo, $type, $namestatus, $show)
	{
		$result = [];

		if ($this->done === false && $this->relid !== $release["releases_id"]) {

			if (preg_match('/\w[-\w.\',;& ]+(ASIA|DLC|EUR|GOTY|JPN|KOR|MULTI\d{1}|NTSCU?|PAL|RF|Region[._ -]?Free|USA|XBLA)[._ -](DLC[._ -]Complete|FRENCH|GERMAN|MULTI\d{1}|PROPER|PSN|READ[._ -]?NFO|UMD)?[._ -]?(GC|NDS|NGC|PS3|PSP|WII|XBOX(360)?)[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
				$this->updateRelease($release, $result["0"], $method = "gameCheck: Videogames 1", $echo, $type, $namestatus, $show);
			} else if (preg_match('/\w[-\w.\',;& ]+(GC|NDS|NGC|PS3|WII|XBOX(360)?)[._ -](DUPLEX|iNSOMNi|OneUp|STRANGE|SWAG|SKY)[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
				$this->updateRelease($release, $result["0"], $method = "gameCheck: Videogames 2", $echo, $type, $namestatus, $show);
			} else if (preg_match('/\w[\w.\',;-].+-OUTLAWS/i', $release["textstring"], $result)) {
				$result = str_replace("OUTLAWS", "PC GAME OUTLAWS", $result['0']);
				$this->updateRelease($release, $result["0"], $method = "gameCheck: PC Games -OUTLAWS", $echo, $type, $namestatus, $show);
			} else if (preg_match('/\w[\w.\',;-].+\-ALiAS/i', $release["textstring"], $result)) {
				$newresult = str_replace("-ALiAS", " PC GAME ALiAS", $result['0']);
				$this->updateRelease($release, $newresult, $method = "gameCheck: PC Games -ALiAS", $echo, $type, $namestatus, $show);
			}
		}
	}

	/**
	 * Look for a app name.
	 *
	 * @param $release
	 * @param boolean $echo
	 * @param string $type
	 * @param $namestatus
	 * @param $show
	 */
	public function appCheck($release, $echo, $type, $namestatus, $show)
	{
		$result = [];

		if ($this->done === false && $this->relid !== $release["releases_id"]) {

			if (preg_match('/\w[-\w.\',;& ]+(\d{1,10}|Linux|UNIX)[._ -](RPM)?[._ -]?(X64)?[._ -]?(Incl)[._ -](Keygen)[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
				$this->updateRelease($release, $result["0"], $method = "appCheck: Apps 1", $echo, $type, $namestatus, $show);
			} else if (preg_match('/\w[-\w.\',;& ]+\d{1,8}[._ -](winall-freeware)[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
				$this->updateRelease($release, $result["0"], $method = "appCheck: Apps 2", $echo, $type, $namestatus, $show);
			}
		}
	}

	/*
	 * Just for NFOS.
	 */

	/**
	 * TV.
	 *
	 * @param $release
	 * @param boolean $echo
	 * @param string $type
	 * @param $namestatus
	 * @param $show
	 */
	public function nfoCheckTV($release, $echo, $type, $namestatus, $show)
	{
		$result = [];

		if ($this->done === false && $this->relid !== $release["releases_id"]) {

			if (preg_match('/:\s*.*[\\\\\/]([A-Z0-9].+?S\d+[.-_ ]?[ED]\d+.+?)\.\w{2,}\s+/i', $release["textstring"], $result)) {
				$this->updateRelease($release, $result["1"], $method = "nfoCheck: Generic TV 1", $echo, $type, $namestatus, $show);
			} else if (preg_match('/(?:(\:\s{1,}))(.+?S\d{1,3}[.-_ ]?[ED]\d{1,3}.+?)(\s{2,}|\r|\n)/i', $release["textstring"], $result)) {
				$this->updateRelease($release, $result["2"], $method = "nfoCheck: Generic TV 2", $echo, $type, $namestatus, $show);
			}
		}
	}

	/**
	 * Movies.
	 *
	 * @param $release
	 * @param boolean $echo
	 * @param string $type
	 * @param $namestatus
	 * @param $show
	 */
	public function nfoCheckMov($release, $echo, $type, $namestatus, $show)
	{
		$result = [];

		if ($this->done === false && $this->relid !== $release["releases_id"]) {

			if (preg_match('/(?:((?!Source\s)\:\s{1,}))(.+?(19|20)\d\d.+?(BDRip|bluray|DVD(R|Rip)?|XVID).+?)(\s{2,}|\r|\n)/i', $release["textstring"], $result)) {
				$this->updateRelease($release, $result["2"], $method = "nfoCheck: Generic Movies 1", $echo, $type, $namestatus, $show);
			} else if (preg_match('/(?:(\s{2,}))((?!Source).+?[\.\-_ ](19|20)\d\d.+?(BDRip|bluray|DVD(R|Rip)?|XVID).+?)(\s{2,}|\r|\n)/i', $release["textstring"], $result)) {
				$this->updateRelease($release, $result["2"], $method = "nfoCheck: Generic Movies 2", $echo, $type, $namestatus, $show);
			} else if (preg_match('/(?:(\s{2,}))(.+?[\.\-_ ](NTSC|MULTi).+?(MULTi|DVDR)[\.\-_ ].+?)(\s{2,}|\r|\n)/i', $release["textstring"], $result)) {
				$this->updateRelease($release, $result["2"], $method = "nfoCheck: Generic Movies 3", $echo, $type, $namestatus, $show);
			}
		}
	}

	/**
	 * @param $release
	 * @param boolean $echo
	 * @param string $type
	 * @param $namestatus
	 * @param $show
	 */
	public function nfoCheckMus($release, $echo, $type, $namestatus, $show)
	{
		$result = [];

		if ($this->done === false && $this->relid !== $release["releases_id"]) {

			if (preg_match('/(?:\s{2,})(.+?-FM-\d{2}-\d{2})/i', $release["textstring"], $result)) {
				$newname = str_replace('-FM-', '-FM-Radio-MP3-', $result["1"]);
				$this->updateRelease($release, $newname, $method = "nfoCheck: Music FM RADIO", $echo, $type, $namestatus, $show);
			}
		}
	}

	/**
	 * Title (year)
	 *
	 * @param $release
	 * @param boolean $echo
	 * @param string $type
	 * @param $namestatus
	 * @param $show
	 */
	public function nfoCheckTY($release, $echo, $type, $namestatus, $show)
	{
		$result = [];

		if ($this->done === false && $this->relid !== $release["releases_id"]) {
			if (preg_match('/(\w[-\w`~!@#$%^&*()_+={}|"<>?\[\]\\;\',.\/ ]+\s?\((19|20)\d\d\))/i', $release["textstring"], $result) && !preg_match('/\.pdf|Audio ?Book/i', $release["textstring"])) {
				$releasename = $result[0];
				if (preg_match('/(idiomas|lang|language|langue|sprache).*?\b(?P<lang>Brazilian|Chinese|Croatian|Danish|DE|Deutsch|Dutch|Estonian|ES|English|Englisch|Finnish|Flemish|Francais|French|FR|German|Greek|Hebrew|Icelandic|Italian|Japenese|Japan|Japanese|Korean|Latin|Nordic|Norwegian|Polish|Portuguese|Russian|Serbian|Slovenian|Swedish|Spanisch|Spanish|Thai|Turkish)\b/i', $release["textstring"], $result)) {
					switch ($result['lang']) {
						case 'DE':
							$result['lang'] = 'DUTCH';
							break;
						case 'Englisch':
							$result['lang'] = 'ENGLISH';
							break;
						case 'FR':
							$result['lang'] = 'FRENCH';
							break;
						case 'ES':
							$result['lang'] = 'SPANISH';
							break;
						default:
							break;
					}
					$releasename = $releasename . "." . $result['lang'];
				}

				if (preg_match('/(frame size|(video )?res(olution)?|video).*?(?P<res>(272|336|480|494|528|608|\(?640|688|704|720x480|810|816|820|1 ?080|1280( \@)?|1 ?920(x1080)?))/i', $release["textstring"], $result)) {
					switch ($result['res']) {
						case '272':
						case '336':
						case '480':
						case '494':
						case '608':
						case '640':
						case '(640':
						case '688':
						case '704':
						case '720x480':
							$result['res'] = '480p';
							break;
						case '1280x720':
						case '1280':
						case '1280 @':
							$result['res'] = '720p';
							break;
						case '810':
						case '816':
						case '820':
						case '1920':
						case '1 920':
						case '1080':
						case '1 080':
						case '1920x1080':
							$result['res'] = '1080p';
							break;
					}

					$releasename = $releasename . "." . $result['res'];
				} else if (preg_match('/(largeur|width).*?(?P<res>(\(?640|688|704|720|1280( \@)?|1 ?920))/i', $release["textstring"], $result)) {
					switch ($result['res']) {
						case '640':
						case '(640':
						case '688':
						case '704':
						case '720':
							$result['res'] = '480p';
							break;
						case '1280 @':
						case '1280':
							$result['res'] = '720p';
							break;
						case '1920':
						case '1 920':
							$result['res'] = '1080p';
							break;
					}

					$releasename = $releasename . "." . $result['res'];
				}

				if (preg_match('/source.*?\b(?P<source>BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)\b/i', $release["textstring"], $result)) {

					switch ($result['source']) {
						case 'BD':
							$result['source'] = 'Bluray.x264';
							break;
						case 'CAMRIP':
							$result['source'] = 'CAM';
							break;
						case 'DBrip':
							$result['source'] = 'BDRIP';
							break;
						case 'DVD R1':
						case 'NTSC':
						case 'PAL':
						case 'VOD':
							$result['source'] = 'DVD';
							break;
						case 'HD':
							$result['source'] = 'HDTV';
							break;
						case 'Ripped ':
							$result['source'] = 'DVDRIP';
					}

					$releasename = $releasename . "." . $result['source'];
				} else if (preg_match('/(codec( (name|code))?|(original )?format|res(olution)|video( (codec|format|res))?|tv system|type|writing library).*?\b(?P<video>AVC|AVI|DBrip|DIVX|\(Divx|DVD|[HX][._ -]?264|MPEG-4 Visual|NTSC|PAL|WMV|XVID)\b/i', $release["textstring"], $result)) {
					switch ($result['video']) {
						case 'AVI':
							$result['video'] = 'DVDRIP';
							break;
						case 'DBrip':
							$result['video'] = 'BDRIP';
							break;
						case '(Divx':
							$result['video'] = 'DIVX';
							break;
						case 'h264':
						case 'h-264':
						case 'h.264':
							$result['video'] = 'H264';
							break;
						case 'MPEG-4 Visual':
						case 'x264':
						case 'x-264':
						case 'x.264':
							$result['video'] = 'x264';
							break;
						case 'NTSC':
						case 'PAL':
							$result['video'] = 'DVD';
							break;
					}

					$releasename = $releasename . "." . $result['video'];
				}

				if (preg_match('/(audio( format)?|codec( name)?|format).*?\b(?P<audio>0x0055 MPEG-1 Layer 3|AAC( LC)?|AC-?3|\(AC3|DD5(.1)?|(A_)?DTS-?(HD)?|Dolby(\s?TrueHD)?|TrueHD|FLAC|MP3)\b/i', $release["textstring"], $result)) {

					switch ($result['audio']) {
						case '0x0055 MPEG-1 Layer 3':
							$result['audio'] = 'MP3';
							break;
						case 'AC-3':
						case '(AC3':
							$result['audio'] = 'AC3';
							break;
						case 'AAC LC':
							$result['audio'] = 'AAC';
							break;
						case 'A_DTS':
						case 'DTS-HD':
						case 'DTSHD':
							$result['audio'] = 'DTS';
					}
					$releasename = $releasename . "." . $result['audio'];
				}
				$releasename = $releasename . "-NoGroup";
				$this->updateRelease($release, $releasename, $method = "nfoCheck: Title (Year)", $echo, $type, $namestatus, $show);
			}
		}
	}

	/**
	 * Games.
	 *
	 * @param $release
	 * @param boolean $echo
	 * @param string $type
	 * @param $namestatus
	 * @param $show
	 */
	public function nfoCheckG($release, $echo, $type, $namestatus, $show)
	{
		$result = [];

		if ($this->done === false && $this->relid !== $release["releases_id"]) {
			if (preg_match('/ALiAS|BAT-TEAM|FAiRLiGHT|Game Type|Glamoury|HI2U|iTWINS|JAGUAR|(LARGE|MEDIUM)ISO|MAZE|nERv|PROPHET|PROFiT|PROCYON|RELOADED|REVOLVER|ROGUE|ViTALiTY/i', $release["textstring"])) {
				if (preg_match('/\w[\w.+&*\/\()\',;: -]+\(c\)[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
					$releasename = str_replace(["(c)", "(C)"], "(GAMES) (c)", $result['0']);
					$this->updateRelease($release, $releasename, $method = "nfoCheck: PC Games (c)", $echo, $type, $namestatus, $show);
				} else if (preg_match('/\w[\w.+&*\/()\',;: -]+\*ISO\*/i', $release["textstring"], $result)) {
					$releasename = str_replace("*ISO*", "*ISO* (PC GAMES)", $result['0']);
					$this->updateRelease($release, $releasename, $method = "nfoCheck: PC Games *ISO*", $echo, $type, $namestatus, $show);
				}
			}
		}
	}

	//
	/**
	 * Misc.
	 *
	 * @param $release
	 * @param boolean $echo
	 * @param string $type
	 * @param $namestatus
	 * @param $show
	 */
	public function nfoCheckMisc($release, $echo, $type, $namestatus, $show)
	{
		if ($this->done === false && $this->relid !== $release["releases_id"]) {

			if (preg_match('/Supplier.+?IGUANA/i', $release["textstring"])) {
				$releasename = '';
				$result = [];
				if (preg_match('/\w[-\w`~!@#$%^&*()+={}|:"<>?\[\]\\;\',.\/ ]+\s\((19|20)\d\d\)/i', $release["textstring"], $result)) {
					$releasename = $result[0];
				} else if (preg_match('/\s\[\*\] (English|Dutch|French|German|Spanish)\b/i', $release["textstring"], $result)) {
					$releasename = $releasename . "." . $result[1];
				} else if (preg_match('/\s\[\*\] (DT?S [2567][._ -][0-2]( MONO)?)\b/i', $release["textstring"], $result)) {
					$releasename = $releasename . "." . $result[2];
				} else if (preg_match('/Format.+(DVD(5|9|R)?|[HX][._ -]?264)\b/i', $release["textstring"], $result)) {
					$releasename = $releasename . "." . $result[1];
				} else if (preg_match('/\[(640x.+|1280x.+|1920x.+)\] Resolution\b/i', $release["textstring"], $result)) {
					if ($result[1] == '640x.+') {
						$result[1] = '480p';
					} else if ($result[1] == '1280x.+') {
						$result[1] = '720p';
					} else if ($result[1] == '1920x.+') {
						$result[1] = '1080p';
					}
					$releasename = $releasename . "." . $result[1];
				}
				$result = $releasename . ".IGUANA";
				$this->updateRelease($release, $result, $method = "nfoCheck: IGUANA", $echo, $type, $namestatus, $show);
			}
		}
	}

	/**
	 * Just for filenames.
	 *
	 * @param $release
	 * @param boolean $echo
	 * @param string $type
	 * @param $namestatus
	 * @param $show
	 */
	public function fileCheck($release, $echo, $type, $namestatus, $show)
	{
		$result = [];

		if ($this->done === false && $this->relid !== $release["releases_id"]) {

			if (preg_match('/^(.+?(x264|XviD)\-TVP)\\\\/i', $release["textstring"], $result)) {
				$this->updateRelease($release, $result["1"], $method = "fileCheck: TVP", $echo, $type, $namestatus, $show);
			} else if (preg_match('/^(\\\\|\/)?(.+(\\\\|\/))*(.+?S\d{1,3}[.-_ ]?[ED]\d{1,3}.+)\.(.+)$/i', $release["textstring"], $result)) {
				$this->updateRelease($release, $result["4"], $method = "fileCheck: Generic TV", $echo, $type, $namestatus, $show);
			} else if (preg_match('/^(\\\\|\/)?(.+(\\\\|\/))*(.+?([\.\-_ ]\d{4}[\.\-_ ].+?(BDRip|bluray|DVDRip|XVID)).+)\.(.+)$/i', $release["textstring"], $result)) {
				$this->updateRelease($release, $result["4"], $method = "fileCheck: Generic movie 1", $echo, $type, $namestatus, $show);
			} else if (preg_match('/^([a-z0-9\.\-_]+(19|20)\d\d[a-z0-9\.\-_]+[\.\-_ ](720p|1080p|BDRip|bluray|DVDRip|x264|XviD)[a-z0-9\.\-_]+)\.[a-z]{2,}$/i', $release["textstring"], $result)) {
				$this->updateRelease($release, $result["1"], $method = "fileCheck: Generic movie 2", $echo, $type, $namestatus, $show);
			} else if (preg_match('/(.+?([\.\-_ ](CD|FM)|[\.\-_ ]\dCD|CDR|FLAC|SAT|WEB).+?(19|20)\d\d.+?)\\\\.+/i', $release["textstring"], $result)) {
				$this->updateRelease($release, $result["1"], $method = "fileCheck: Generic music", $echo, $type, $namestatus, $show);
			} else if (preg_match('/^(.+?(19|20)\d\d\-([a-z0-9]{3}|[a-z]{2,}|C4))\\\\/i', $release["textstring"], $result)) {
				$this->updateRelease($release, $result["1"], $method = "fileCheck: music groups", $echo, $type, $namestatus, $show);
			} else if (preg_match('/.+\\\\(.+\((19|20)\d\d\)\.avi)/i', $release["textstring"], $result)) {
				$newname = str_replace('.avi', ' DVDRip XVID NoGroup', $result["1"]);
				$this->updateRelease($release, $newname, $method = "fileCheck: Movie (year) avi", $echo, $type, $namestatus, $show);
			} else if (preg_match('/.+\\\\(.+\((19|20)\d\d\)\.iso)/i', $release["textstring"], $result)) {
				$newname = str_replace('.iso', ' DVD NoGroup', $result["1"]);
				$this->updateRelease($release, $newname, $method = "fileCheck: Movie (year) iso", $echo, $type, $namestatus, $show);
			} else if (preg_match('/^(.+?IMAGESET.+?)\\\\.+/i', $release["textstring"], $result)) {
				$this->updateRelease($release, $result["1"], $method = "fileCheck: XXX Imagesets", $echo, $type, $namestatus, $show);
			} else if (preg_match('/^VIDEOOT-[A-Z0-9]+\\\\([\w!.,& ()\[\]\'\`-]{8,}?\b.?)([-_](proof|sample|thumbs?))*(\.part\d*(\.rar)?|\.rar|\.7z)?(\d{1,3}\.rev|\.vol.+?|\.mp4)/', $release["textstring"], $result)) {
					$this->updateRelease($release, $result["1"] . " XXX DVDRIP XviD-VIDEOOT", $method = "fileCheck: XXX XviD VIDEOOT", $echo, $type, $namestatus, $show);
			} else if (preg_match('/\w[-\w.\',;& ]+1080i[._ -]DD5[._ -]1[._ -]MPEG2-R&C(?=\.ts)/i', $release["textstring"], $result)) {
				$result = str_replace("MPEG2", "MPEG2.HDTV", $result["0"]);
				$this->updateRelease($release, $result, $method = "fileCheck: R&C", $echo, $type, $namestatus, $show);
			} else if (preg_match('/\w[-\w.\',;& ]+((s\d{1,2}[._ -]?[bde]\d{1,2})|\d{1,2}x\d{2}|ep[._ -]?\d{2})[._ -](480|720|1080)[ip][._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -]nSD[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[._ -]NhaNC3[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
				$this->updateRelease($release, $result["0"], $method = "fileCheck: NhaNc3", $echo, $type, $namestatus, $show);
			} else if (preg_match('/\wtvp-[\w.\-\',;]+((s\d{1,2}[._ -]?[bde]\d{1,2})|\d{1,2}x\d{2}|ep[._ -]?\d{2})[._ -](720p|1080p|xvid)(?=\.(avi|mkv))/i', $release["textstring"], $result)) {
				$result = str_replace("720p", "720p.HDTV.X264", $result['0']);
				$result = str_replace("1080p", "1080p.Bluray.X264", $result['0']);
				$result = str_replace("xvid", "XVID.DVDrip", $result['0']);
				$this->updateRelease($release, $result, $method = "fileCheck: tvp", $echo, $type, $namestatus, $show);
			} else if (preg_match('/\w[-\w.\',;& ]+\d{3,4}\.hdtv-lol\.(avi|mp4|mkv|ts|nfo|nzb)/i', $release["textstring"], $result)) {
				$this->updateRelease($release, $result["0"], $method = "fileCheck: Title.211.hdtv-lol.extension", $echo, $type, $namestatus, $show);
			} else if (preg_match('/\w[-\w.\',;& ]+-S\d{1,2}[EX]\d{1,2}-XVID-DL.avi/i', $release["textstring"], $result)) {
				$this->updateRelease($release, $result["0"], $method = "fileCheck: Title-SxxExx-XVID-DL.avi", $echo, $type, $namestatus, $show);
			} else if (preg_match('/\S.*[\w.\-\',;]+\s\-\ss\d{2}[ex]\d{2}\s\-\s[\w.\-\',;].+\./i', $release["textstring"], $result)) {
				$this->updateRelease($release, $result["0"], $method = "fileCheck: Title - SxxExx - Eptitle", $echo, $type, $namestatus, $show);
			} else if (preg_match('/\w.+?\)\.nds/i', $release["textstring"], $result)) {
				$this->updateRelease($release, $result["0"], $method = "fileCheck: ).nds Nintendo DS", $echo, $type, $namestatus, $show);
			} else if (preg_match('/3DS_\d{4}.+\d{4} - (.+?)\.3ds/i', $release["textstring"], $result)) {
				$this->updateRelease($release, "3DS " . $result["1"], $method = "fileCheck: .3ds Nintendo 3DS", $echo, $type, $namestatus, $show);
			} else if (preg_match('/\w.+?\.(epub|mobi|azw|opf|fb2|prc|djvu|cb[rz])/i', $release["textstring"], $result)) {
				$result = str_replace("." . $result["1"], " (" . $result["1"] . ")", $result['0']);
				$this->updateRelease($release, $result, $method = "fileCheck: EBook", $echo, $type, $namestatus, $show);
			} else if (preg_match('/\w[-\w.\',;& ]+/i', $release['textstring'], $result) && preg_match(self::PREDB_REGEX, $release['textstring'])) {
				$this->updateRelease($release, $result["0"], $method = "fileCheck: Folder name", $echo, $type, $namestatus, $show);
			}
		}
	}

	/**
	 * Look for a name based on mediainfo xml Unique_ID.
	 *
	 * @param array   $release The release to be matched
	 * @param boolean $echo Should we show CLI output
	 * @param string  $type The rename type
	 * @param int     $namestatus Should we rename the release if match is found
	 * @param int     $show Should we show the rename results
	 *
	 * @return bool Whether or not we matched the release
	 */
	public function uidCheck($release, $echo, $type, $namestatus, $show)
	{
		if ($this->done === false && $this->relid !== $release["releases_id"]) {
			$result = $this->pdo->queryDirect("
				SELECT r.id AS releases_id, r.size AS relsize, r.name AS textstring, r.searchname, r.predb_id
				FROM release_unique ru
				STRAIGHT_JOIN releases r ON ru.releases_id = r.id
				WHERE ru.uniqueid = UNHEX({$this->pdo->escapeString($release['uid'])})
				AND ru.releases_id != {$release['releases_id']}
				AND (r.predb_id > 0 OR r.anidbid > 0 OR r.fromname = 'nonscene@Ef.net (EF)')"
			);

			if ($result instanceof \Traversable) {
				foreach ($result AS $res) {
					$floor = round(($res['relsize'] - $release['relsize']) / $res['relsize'] * 100, 0);
					if ($floor >= -10 && $floor <= 10) {
						$this->updateRelease(
							$release,
							$res['searchname'],
							$method = "uidCheck: Unique_ID",
							$echo,
							$type,
							$namestatus,
							$show,
							$res['predb_id']
						);
						return true;
					}
				}
			}
		}
		$this->_updateSingleColumn('proc_uid', self::PROC_UID_DONE, $release['releases_id']);
		return false;
	}

	/**
	 * Resets NameFixer status variables for new processing
	 */
	public function reset()
	{
		$this->done = $this->matched = false;
	}
}
