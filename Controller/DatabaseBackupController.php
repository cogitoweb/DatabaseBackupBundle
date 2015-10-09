<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace Cogitoweb\DatabaseBackupBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Description of DatabaseBackupController
 *
 * @author Daniele Artico <daniele.artico@cogitoweb.it>
 */
class DatabaseBackupController extends Controller
{
	const FILENAME_PATTERN          = '%s_%s_%s.backup';
	const FILENAME_DATETIME_FORMAT  = 'YmdHis';
	
	const POSTGRES_SCHEMA_PATTERN   = 'pg_dump --host %s --port %d --username %s --no-password --schema-only --file %s %s';
	const POSTGRES_BACKUP_PATTERN   = 'pg_dump --host %s --port %d --username %s --no-password --format custom --blobs --file %s %s';
	
	const CONFIG_FILE               = 'Cogitoweb\DatabaseBackupBundle\Resources\config\services.yml';
	const DATABASE_BACKUP_DIR_PARAM = 'database_backup_dir';
	
	/**
	 * Execute database backup
	 * 
	 * @param  Request $request
	 * @return RedirectResponse
	 */
	public function execAction(Request $request)
    {
		// Setup database params
		$host      = $this->container->getParameter('database_host');
		$port      = $this->container->getParameter('database_port');
		$username  = $this->container->getParameter('database_user');
		$password  = $this->container->getParameter('database_password');
		$database  = $this->container->getParameter('database_name');
		
		try {
			/*
			 * 1st step: get MD5 checksum of the database schema.
			 * 
			 * The checksum will be used in the future to implement a restore function.
			 * The restore function will work only if the underlying database schema has not changed.
			 */

			// Check if system temp folder exists and PHP has write permission
			$this->tempFolderExists();
			$this->canWrite(sys_get_temp_dir());
			
			// Setup temp filename
			$filename = $this->getTempFilename();

			// Customize schema dump command based on underlying database
			$command = $this->getSchemaDumpCommand($host, $port, $username, $password, $database, $filename);

			// Execute schema dump
			$this->execCommand($command);

			// Calculate MD5 of schema
			$md5 = md5_file($filename);

			// Check if PHP has write permission to delete the generated temp file and delete it
			$this->canWrite($filename);
			unlink($filename);
			
			/*
			 * 2nd step: do backup
			 */
			
			// Check if destination dir exists
			$this->databaseBackupDirectoryExists();
			
			// Get destination dir and check if PHP has write permission
			$dirname = $this->getDatabaseBackupFolder();
			$this->canWrite($dirname);

			// Setup backup filename
			$filename = $this->getBackupFilename($dirname, $database, new \DateTime(), $md5);

			// Customize backup command based on underlying database
			$command = $this->getBackupCommand($host, $port, $username, $password, $database, $filename);

			// Execute backup
			$this->execCommand($command);
		
			$this->addFlash('success', $this->get('translator')->trans('exec.flash_success', [], 'CogitowebDatabaseBackupBundle'));
		} catch (\Exception $e) {
			$this->addFlash('danger', $e->getMessage());
			$this->addFlash('danger', $this->get('translator')->trans('exec.flash_error', [], 'CogitowebDatabaseBackupBundle'));
		}
		
		return new RedirectResponse($this->generateUrl('cogitoweb_database_backup_list'));
    }
	
	/**
	 * List and manage database backups
	 * 
	 * @param  Request $request
	 * @return Response
	 */
	public function listAction(Request $request)
	{
		// Check if destination dir exists
		$this->databaseBackupDirectoryExists();
		
		// Get destination dir and check if PHP has read permission
		$dirname = $this->getDatabaseBackupFolder();
		$this->canRead($dirname);
		
		// Setup finder
		$finder = new Finder();
		$finder
			// List all files...
			->files()
			// ... in DATABASE_BACKUP_DIR...
			->in($dirname)
			// Do not list subfolders
			->depth('0')
			// Ordered by mtime
			->sortByModifiedTime()
			// Show newer first
			->sort(function (SplFileInfo $a, SplFileInfo $b) {
				return strcmp($b->getMTime(), $a->getMTime());
			})
		;
		
		$files  = [];
		
		foreach ($finder as $file) { /* @var $file SplFileInfo */
			$mtime = \DateTime::createFromFormat('U', $file->getMTime()); /* @var $mtime \DateTime */
			
			// Convert from utc to local
			$timezone = new \DateTimeZone(date_default_timezone_get());
			$mtime->setTimezone($timezone);
			
			// mtime is formatted here instead of template for compatibility with js
			$files[] = [
				'pathname' => $file->getRelativePathname(),
				'mtime'    => $mtime->format('d/m/Y H:i:s')
			];
		}
		
		return $this->render('CogitowebDatabaseBackupBundle::contents.html.twig', [
			'dirname' => $dirname,
			'files'   => $files
		]);
	}
	
	/**
	 * Delete backup file
	 * 
	 * @param  Request $request
	 * @return RedirectResponse
	 * @throws IOException
	 */
	public function deleteAction(Request $request)
	{
		try {
			// Check if destination dir exists
			$this->databaseBackupDirectoryExists();
			
			// Get destination dir and check if PHP has write permission
			$dirname = $this->getDatabaseBackupFolder();
			$this->canWrite($dirname);
			
			// Get the full path of backup file
			$filename = join(DIRECTORY_SEPARATOR, [$dirname, $request->get('pathname')]);
			
			// Check if backup file exists and PHP has write permission
			$this->fileExists($filename);
			$this->canWrite  ($filename);
			
			if (!unlink($filename)) {
				// File exists and is writable, but maybe it's used by another process
				throw new IOException(sprintf(
					'cannot delete file "%s". Check if file is used by another process',
					$filename
				));
			}
			
			$this->addFlash('success', $this->get('translator')->trans('delete.flash_success', [], 'CogitowebDatabaseBackupBundle'));
		} catch (\Exception $e) {
			$this->addFlash('danger', $e->getMessage());
			$this->addFlash('danger', $this->get('translator')->trans('delete.flash_error', [], 'CogitowebDatabaseBackupBundle'));
		}
		
		return new RedirectResponse($this->generateUrl('cogitoweb_database_backup_list'));
	}
	
	/**
	 * @return string
	 */
	protected function getDatabaseBackupFolder()
	{
		return realpath($this->container->getParameter(self::DATABASE_BACKUP_DIR_PARAM));
	}
	
	/**
	 * @return string
	 */
	protected function getTempFilename()
	{
		return join(DIRECTORY_SEPARATOR, [sys_get_temp_dir(), uniqid()]);
	}
	
	/**
	 * @param  string    $dirname
	 * @param  string    $database
	 * @param  \DateTime $dateTime
	 * @param  string    $md5
	 * @return string
	 */
	protected function getBackupFilename($dirname, $database, \DateTime $dateTime, $md5)
	{
		$filename = sprintf(
			self::FILENAME_PATTERN,
			$database,
			$dateTime->format(self::FILENAME_DATETIME_FORMAT),
			$md5
		);
		
		// Join filename and dirname
		return join(DIRECTORY_SEPARATOR, [$dirname, $filename]);
	}
	
	/**
	 * Get the command to execute a dump of the database schema on the filesystem
	 * 
	 * @param  string $host
	 * @param  string $port
	 * @param  string $username
	 * @param  string $password
	 * @param  string $database
	 * @param  string $filename
	 * @return string
	 */
	protected function getSchemaDumpCommand($host, $port, $username, $password, $database, $filename)
	{
		$driver = $this->container->getParameter('database_driver');
		
		switch ($driver) {
			case 'pdo_pgsql':
				$host     = $host     ?: 'localhost';
				$port     = $port     ?: 5432;
				$username = $username ?: 'postgres';
				$databse  = $database ?: 'postgres';
				
				$this->addFlash('info', $this->get('translator')->trans('exec.flash_info_no_password', [], 'CogitowebDatabaseBackupBundle'));
					
				return sprintf(self::POSTGRES_SCHEMA_PATTERN, $host, $port, $username, $filename, $database);
			default:
				
		}
	}
	
	/**
	 * Get the command to execute the backup of the database on the filesystem
	 * 
	 * @param  string $host
	 * @param  string $port
	 * @param  string $username
	 * @param  string $password
	 * @param  string $database
	 * @param  string $filename
	 * @return string
	 */
	protected function getBackupCommand($host, $port, $username, $password, $database, $filename)
	{
		$driver = $this->container->getParameter('database_driver');
		
		switch ($driver) {
			case 'pdo_pgsql':
				$host     = $host     ?: 'localhost';
				$port     = $port     ?: 5432;
				$username = $username ?: 'postgres';
				$databse  = $database ?: 'postgres';
				
				$this->addFlash('info', $this->get('translator')->trans('exec.flash_info_no_password', [], 'CogitowebDatabaseBackupBundle'));
				
				return sprintf(self::POSTGRES_BACKUP_PATTERN, $host, $port, $username, $filename, $database);
			default:
				
		}
	}
	
	/**
	 * @param  string $command
	 * @throws \Exception
	 */
	protected function execCommand($command)
	{
		$output = [];
		$return = -1;
		
		exec($command, $output, $return);
		
		if ($return !== 0) {
			throw new \Exception(sprintf(
				'an error has occurred during the execution of "%s" with output "%s"',
				$command,
				join(PHP_EOL, $output)
			));
		}
	}
	
	/**
	 * Check if database backup directory exists
	 * 
	 * @throws \InvalidArgumentException
	 */
	protected function databaseBackupDirectoryExists()
	{
		$dirname = $this->getDatabaseBackupFolder();
		
		if (!file_exists($dirname)) {
			throw new \InvalidArgumentException(sprintf(
				'the database backup directory "%s" does not exist. Check parameter "%s" in config file "%s"',
				$path,
				self::DATABASE_BACKUP_DIR_PARAM,
				self::CONFIG_FILE
			));
		}
	}
	
	/**
	 * Check if system temp directory exists
	 * 
	 * @throws \InvalidArgumentException
	 */
	protected function tempFolderExists()
	{
		$dirname = sys_get_temp_dir();
		
		if (!file_exists($dirname)) {
			throw new \InvalidArgumentException(sprintf(
				'the system temp directory "%s" does not exist',
				$dirname
			));
		}
	}
	
	/**
	 * Check if file exists
	 * 
	 * @param  string $filename
	 * @throws \InvalidArgumentException
	 */
	protected function fileExists($filename)
	{
		if (!file_exists($filename)) {
			throw new \InvalidArgumentException(sprintf(
				'the file "%s" does not exist',
				$filename
			));
		}
	}
	
	/**
	 * Check if PHP has read permission
	 * 
	 * @param  string $path
	 * @throws IOException
	 */
	protected function canRead($path)
	{
		if (!is_readable($path)) {
			throw new IOException(sprintf(
				'the directory/file "%s" is not readable. Check permissions for user "%s"',
				$path,
				exec('whoami')
			));
		}
	}
	
	/**
	 * Check if PHP has write permission
	 * 
	 * @param  string $path
	 * @throws IOException
	 */
	protected function canWrite($path)
	{
		if (!is_writable($path)) {
			throw new IOException(sprintf(
				'the directory/file "%s" is not writable. Check permissions for user "%s"',
				$path,
				exec('whoami')
			));
		}
	}

	/**
	 * Shortcut to add flash messages
	 * 
	 * @param string $type
	 * @param string $message
	 */
	protected function addFlash($type, $message)
	{
		$this->getRequest()->getSession()->getFlashBag()->add($type, $message);
	}
}
