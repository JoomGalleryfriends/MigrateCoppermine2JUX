<?php
/******************************************************************************\
**   JoomGallery Migration Script CPG2JUX 1.0 Beta1                           **
**   By: JoomGallery::ProjectTeam                                             **
**   Copyright (C) 2014 - 2015 JoomGallery::ProjectTeam                       **
**   Released under GNU GPL Public License                                    **
**   License: http://www.gnu.org/copyleft/gpl.html or have a look             **
**   at administrator/components/com_joomgallery/LICENSE.TXT                  **
\******************************************************************************/

/******************************************************************************\
**   Migration of DB and Files from Coppermine to Joomgallery 3 JUX           **
**   On the fly generation of categories in db and file system                **
**   moving the images into the new categories                                **
\******************************************************************************/

defined('_JEXEC') or die('Direct Access to this location is not allowed.');

/**
 * Migration script class
 *
 * @package JoomGallery
 * @since   3.0
 */
class JoomMigratecpg2Jux extends JoomMigration
{
  /**
   * The name of the migration
   * (should be unique)
   *
   * @var string
   */
  protected $migration = 'cpg2jux';

  /**
   * Properties for paths and database table names of old Coppermine to migrate from
   *
   * @var string
   */
  protected $prefix;
  protected $path;
  protected $path_originals;
  protected $path_details;
  protected $path_thumbnails;
  protected $table_images;
  protected $table_categories;
  protected $table_comments;
 // protected $table_users;
  protected $table_votes;

  /**
   * Constructor
   *
   * @return  void
   * @since   3.0
   */
  public function __construct()
  {
    parent::__construct();

    // Create the image paths and table names, a '/' at the end of the path is not allowed!
    $prefix = $this->getStateFromRequest('prefix', 'prefix', '', 'cmd');
    $path   = $this->getStateFromRequest('path', 'path', '', 'string');
    $this->path_originals     = JPath::clean($path.'/albums/');
	$this->path_details		  = JPath::clean($path.'/albums/');
    $this->path_thumbnails    = JPath::clean($path.'/albums/');
    $this->table_images       = $prefix.'pictures';
    $this->table_categories   = $prefix.'albums';
    $this->table_comments     = $prefix.'comments';
   // $this->table_votes        = $prefix.'votes';
   // $this->table_img_details  = str_replace('#__', $this->prefix, _JOOM_TABLE_IMAGE_DETAILS);
   // $this->table_cat_details  = str_replace('#__', $this->prefix, _JOOM_TABLE_CATEGORY_DETAILS);
  }

  /**
   * Checks requirements for migration
   *
   * @return  void
   * @since   3.0
   */
  public function check($dirs = array(), $tables = array(), $xml = false, $min_version = false, $max_version = false)
  {
    if($this->path == JPATH_ROOT)
    {
      JFactory::getLanguage()->load('com_joomgallery.migratecpg2jux');
      $this->_mainframe->redirect('index.php?option='._JOOM_OPTION.'&controller=migration', JText::_('FILES_JOOMGALLERY_MIGRATION_CPG2JUX_WRONG_PATH2CPG'), 'notice');
    }

    if(!$this->otherDatabase && $this->prefix == $this->_db->getPrefix())
    {
      JFactory::getLanguage()->load('com_joomgallery.migratecpg2jux');
      $this->_mainframe->redirect('index.php?option='._JOOM_OPTION.'&controller=migration', JText::_('FILES_JOOMGALLERY_MIGRATION_CPG2JUX_WRONG_PREFIX'), 'notice');
    }

    $dirs         = array($this->path_originals,
                          $this->path_details,
                          $this->path_thumbnails);
    $tables       = array($this->table_images,
                          $this->table_categories,
                          $this->table_comments);
//                          $this->table_users,
//                          $this->table_votes);


    parent::check($dirs, $tables, $xml, $min_version, $max_version);
  }

  /**
   * Main migration function
   *
   * @return  void
   * @since   3.0
   */
  protected function doMigration()
  {
    $task = $this->getTask('categories');

    switch($task)
    {
      case 'categories':
        $this->migrateCategories();
        // Break intentionally omited
      case 'rebuild':
        $this->rebuild();
        // Break intentionally omited
      case 'images':
        $this->migrateImages();
        // Break intentionally omited
      case 'comments':
        $this->migrateComments();
        // Break intentionally omited
      default:
        break;
    }
  }

  /**
   * Returns the maximum category ID of Coppermine
   *
   * @return  int The maximum category ID of Coppermine
   * @since   3.0
   */
  protected function getMaxCategoryId()
  {
    $query = $this->_db2->getQuery(true)
          ->select('MAX(aid)')
          ->from($this->table_categories);
    $this->_db2->setQuery($query);

    return $this->runQuery('loadResult', $this->_db2);
  }

  /**
   * Migrates all categories
   *
   * @return  void
   * @since   3.0
   */
  protected function migrateCategories()
  {
    $this->writeLogfile('Start migrating categories');

    $query = $this->_db2->getQuery(true)
          ->select('*')
          ->from($this->table_categories);
    $this->prepareTable($query, $this->table_categories, 'category', array(0));

    while($cat = $this->getNextObject())
    {
      // Make information accessible for JoomGallery
      $cat->cid         = $cat->aid+1;
      $cat->name        = $cat->title;
      $cat->description = $cat->description;
      $cat->thumbnail   = $cat->thumb;
      $cat->parent_id   = '1';
      if($cat->visibility == '0') {
        $cat->access    = '1';
      }
      else {
        $cat->access    = $cat->visibility;
      }
      $cat->published   = '1';
      $cat->password    = $cat->alb_password;
	  $cat->owner	    = '0';

      $this->createCategory($cat);

      $this->markAsMigrated($cat->aid, 'aid', $this->table_categories);

      if(!$this->checkTime())
      {
        $this->refresh();
      }
    }

    $this->resetTable($this->table_categories);

    $this->writeLogfile('Categories successfully migrated');
  }

  /**
   * Migrates all images
   *
   * @return  void
   * @since   3.0
   */
  protected function migrateImages()
  {
    $this->writeLogfile('Start migrating images');

    $query = $this->_db2->getQuery(true)
          ->select('i.*')
          ->from($this->table_images.' AS i');
    $this->prepareTable($query);

    while($row = $this->getNextObject())
    {
      $original   = $this->path_originals.$row->filepath.'orig_'.$row->filename;
	  $detail     = $this->path_originals.$row->filepath.'normal_'.$row->filename;
      $thumbnail  = $this->path_originals.$row->filepath.'thumb_'.$row->filename;

      $row->id          = $row->pid;
      $row->catid       = $row->aid+1;
      if($row->title == '') {
        $row->imgtitle   = $row->filename;
      }
      else {
         $row->imgtitle    = $row->title;
      }
      $row->imgtext     = $row->caption;
      //$row->imgdate     = $row->date;
      $row->published   = '1';
      $row->imgfilename = $row->filename;
      $row->imgvotes    = $row->votes;
      $row->imgvotesum  = $row->pic_rating / 2000 * $row->votes;
      $row->hits        = $row->hits;
      $row->owner	    = $row->owner_id;
      if($row->approved == 'YES') {
        $row->approved   = '1';
      }
      else {
        $row->approved   = '0';
      }
	  $row->ordering 	= $row->position;

      $this->moveAndResizeImage($row, $original, $detail, $thumbnail, true);              // Thumbnails and details are moved/copied, not new created

      if(!$this->checkTime())
      {
        $this->refresh('images');
      }
    }

    $this->resetTable();
  }

  /**
   * Migrates all comments
   *
   * @return  void
   * @since   3.0
   */
  protected function migrateComments()
  {
    $this->writeLogfile('Start migrating comments');

    $query = $this->_db2->getQuery(true)
          ->select('*')
          ->from($this->table_comments);
    $this->prepareTable($query);

    while($row = $this->getNextObject())
    {
      $row->cmtid       = $row->msg_id;
      $row->cmtpic      = $row->pid;
      $row->cmtname     = $row->msg_author;
      $row->cmttext     = $row->msg_body;
      $row->cmtip       = $row->msg_raw_ip;
      $row->cmtdate     = $row->msg_date;
      if($row->approval == 'YES') {
        $row->approved   = '1';
      }
      else {
        $row->approved   = '0';
      }
      $row->published    = '1';
	  // $row->userid		= $row->userid;

      $this->createComment($row);

      if(!$this->checkTime())
      {
        $this->refresh('comments');
      }
    }

    $this->resetTable();
  }

  
  /**
   * Migrates all users
   *
   * @return  void
   * @since   3.0
   */
  protected function migrateUsers()
  {
    $selectQuery = $this->_db2->getQuery(true)
                ->select('a.*')
                ->from($this->table_users.' AS a')
                ->where('uuserid != 0');

    if(!$this->otherDatabase)
    {
      if(!$this->checkTime())
      {
        $this->refresh('users');
      }

      $this->writeLogfile('Start migrating users');
      if($this->checkOwner)
      {
        $selectQuery->leftJoin('#__users AS u ON a.uuserid = u.id')
                    ->where('u.id IS NOT NULL');
      }
      $query = 'INSERT INTO '._JOOM_TABLE_USERS.' '.$selectQuery;
      $this->_db->setQuery($query);
      if($this->runQuery())
      {
        $this->writeLogfile('Users successfully migrated');
      }
      else
      {
        $this->writeLogfile('Error migrating the users');
      }

      return;
    }

    $this->prepareTable($selectQuery);

    while($row = $this->getNextObject())
    {
      if(!$this->checkOwner || JUser::getTable()->load($row->uuserid))
      {
        $this->createUser($row);
      }

      if(!$this->checkTime())
      {
        $this->refresh('users');
      }
    }

    $this->resetTable();
  }

  /**
   * Migrates all votes
   *
   * @return  void
   * @since   3.0
   */
  protected function migrateVotes()
  {
    $selectQuery = $this->_db2->getQuery(true)
                ->select('a.*')
                ->from($this->table_votes.' AS a');

    if(!$this->otherDatabase)
    {
      if(!$this->checkTime())
      {
        $this->refresh('votes');
      }

      $this->writeLogfile('Start migrating votes');
      if($this->checkOwner)
      {
        $selectQuery->leftJoin('#__users AS u ON a.userid = u.id')
                    ->where('u.id IS NOT NULL');
      }
      $query = 'INSERT INTO '._JOOM_TABLE_VOTES.' '.$selectQuery;
      $this->_db->setQuery($query);
      if($this->runQuery())
      {
        $this->writeLogfile('Votes successfully migrated');
      }
      else
      {
        $this->writeLogfile('Error migrating the votes');
      }

      return;
    }

    $this->prepareTable($selectQuery);

    while($row = $this->getNextObject())
    {
      if(!$this->checkOwner || JUser::getTable()->load($row->userid))
      {
        $this->createVote($row);
      }

      if(!$this->checkTime())
      {
        $this->refresh('votes');
      }
    }

    $this->resetTable();
  }

  /**
   * Migrates configuration settings (where possible)
   *
   * @return  void
   * @since   3.0
   */
  protected function migrateConfig()                       // not possible
  {
    if(!$this->checkTime())
    {
      $this->refresh('config');
    }
   
  }

  /**
   * Migrates image details (additional fields)
   *
   * @return  void
   * @since   3.0
   */
  protected function migrateImageDetails()                    // not possible
  {
    
  }

  /**
   * Migrates category details (additional fields)
   *
   * @return  void
   * @since   3.0
   */
  protected function migrateCategoryDetails()                 // not possible
  {
    
  }
}