<?php
/**
 * @package     Joomla.Site
 * @subpackage  com_content
 *
 * @copyright   (C) 2006 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Content\Site\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\Categories\Categories;
use Joomla\CMS\Categories\CategoryNode;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Multilanguage;
use Joomla\CMS\MVC\Model\ListModel;
use Joomla\CMS\Table\Table;
use Joomla\Component\Content\Site\Helper\QueryHelper;
use Joomla\Registry\Registry;
use Joomla\Utilities\ArrayHelper;

/**
 * This models supports retrieving a category, the articles associated with the category,
 * sibling, child and parent categories.
 *
 * @since  1.5
 */
class CategoryModel extends ListModel
{
	/**
	 * Category items data
	 *
	 * @var array
	 */
	protected $_item = null;

	/**
	 * Array of articles in the category
	 *
	 * @var    \stdClass[]
	 */
	protected $_articles = null;

	/**
	 * Category left and right of this one
	 *
	 * @var    CategoryNode[]|null
	 */
	protected $_siblings = null;

	/**
	 * Array of child-categories
	 *
	 * @var    CategoryNode[]|null
	 */
	protected $_children = null;

	/**
	 * Parent category of the current one
	 *
	 * @var    CategoryNode|null
	 */
	protected $_parent = null;

	/**
	 * Model context string.
	 *
	 * @var		string
	 */
	protected $_context = 'com_content.category';

	/**
	 * The category that applies.
	 *
	 * @var	   object
	 */
	protected $_category = null;

	/**
	 * The list of categories.
	 *
	 * @var	   array
	 */
	protected $_categories = null;

	/**
	 * Constructor.
	 *
	 * @param   array  $config  An optional associative array of configuration settings.
	 *
	 * @since   1.6
	 */
	public function __construct($config = array())
	{
		if (empty($config['filter_fields']))
		{
			$config['filter_fields'] = array(
				'id', 'a.id',
				'title', 'a.title',
				'alias', 'a.alias',
				'checked_out', 'a.checked_out',
				'checked_out_time', 'a.checked_out_time',
				'catid', 'a.catid', 'category_title',
				'state', 'a.state',
				'access', 'a.access', 'access_level',
				'created', 'a.created',
				'created_by', 'a.created_by',
				'modified', 'a.modified',
				'ordering', 'a.ordering',
				'featured', 'a.featured',
				'language', 'a.language',
				'hits', 'a.hits',
				'publish_up', 'a.publish_up',
				'publish_down', 'a.publish_down',
				'author', 'a.author',
				'filter_tag'
			);
		}

		parent::__construct($config);
	}

	/**
	 * Method to auto-populate the model state.
	 *
	 * Note. Calling getState in this method will result in recursion.
	 *
	 * @param   string  $ordering   The field to order on.
	 * @param   string  $direction  The direction to order on.
	 *
	 * @return  void
	 *
	 * @since   1.6
	 */
	protected function populateState($ordering = null, $direction = null)
	{
		$app = Factory::getApplication();
		$pk  = $app->input->getInt('id');

		$this->setState('category.id', $pk);

		// Load the parameters. Merge Global and Menu Item params into new object
		$params = $app->getParams();

		if ($menu = $app->getMenu()->getActive())
		{
			$menuParams = $menu->getParams();
		}
		else
		{
			$menuParams = new Registry;
		}

		$mergedParams = clone $menuParams;
		$mergedParams->merge($params);

		$this->setState('params', $mergedParams);
		$user  = Factory::getUser();

		$asset = 'com_content';

		if ($pk)
		{
			$asset .= '.category.' . $pk;
		}

		if ((!$user->authorise('core.edit.state', $asset)) &&  (!$user->authorise('core.edit', $asset)))
		{
			// Limit to published for people who can't edit or edit.state.
			$this->setState('filter.published', 1);
		}
		else
		{
			$this->setState('filter.published', [0, 1]);
		}

		// Process show_noauth parameter
		if (!$params->get('show_noauth'))
		{
			$this->setState('filter.access', true);
		}
		else
		{
			$this->setState('filter.access', false);
		}

		$itemid = $app->input->get('id', 0, 'int') . ':' . $app->input->get('Itemid', 0, 'int');

		$value = $this->getUserStateFromRequest('com_content.category.filter.' . $itemid . '.tag', 'filter_tag', 0, 'int', false);
		$this->setState('filter.tag', $value);

		// Optional filter text
		$search = $app->getUserStateFromRequest('com_content.category.list.' . $itemid . '.filter-search', 'filter-search', '', 'string');
		$this->setState('list.filter', $search);

		// Filter.order
		$orderCol = $app->getUserStateFromRequest('com_content.category.list.' . $itemid . '.filter_order', 'filter_order', '', 'string');

		if (!in_array($orderCol, $this->filter_fields))
		{
			$orderCol = 'a.ordering';
		}

		$this->setState('list.ordering', $orderCol);

		$listOrder = $app->getUserStateFromRequest('com_content.category.list.' . $itemid . '.filter_order_Dir', 'filter_order_Dir', '', 'cmd');

		if (!in_array(strtoupper($listOrder), array('ASC', 'DESC', '')))
		{
			$listOrder = 'ASC';
		}

		$this->setState('list.direction', $listOrder);

		$this->setState('list.start', $app->input->get('limitstart', 0, 'uint'));

		// Set limit for query. If list, use parameter. If blog, add blog parameters for limit.
		if (($app->input->get('layout') === 'blog') || $params->get('layout_type') === 'blog')
		{
			$limit = $params->get('num_leading_articles') + $params->get('num_intro_articles') + $params->get('num_links');
			$this->setState('list.links', $params->get('num_links'));
		}
		else
		{
			$limit = $app->getUserStateFromRequest('com_content.category.list.' . $itemid . '.limit', 'limit', $params->get('display_num'), 'uint');
		}

		$this->setState('list.limit', $limit);

		// Set the depth of the category query based on parameter
		$showSubcategories = $params->get('show_subcategory_content', '0');

		if ($showSubcategories)
		{
			$this->setState('filter.max_category_levels', $params->get('show_subcategory_content', '1'));
			$this->setState('filter.subcategories', true);
		}

		$this->setState('filter.language', Multilanguage::isEnabled());

		$this->setState('layout', $app->input->getString('layout'));

		// Set the featured articles state
		$this->setState('filter.featured', $params->get('show_featured'));

        //Arkadiy start
        $value = $this->getUserStateFromRequest('com_content.category.list.' . $itemid . 'filter.article_id_include', 'filter_article_id_include', false, 'boolen');
        $this->setState('filter.article_id.include', $value);
        $value = $this->getUserStateFromRequest('com_content.category.list.' . $itemid . 'filter.article_id', 'filter_article_id', null, 'array');
        $this->setState('filter.article_id', $value);
        //Arkadiy end
	}

	/**
	 * Get the articles in the category
	 *
	 * @return  mixed  An array of articles or false if an error occurs.
	 *
	 * @since   1.5
	 */
	public function getItems()
	{
		$limit = $this->getState('list.limit');

		if ($this->_articles === null && $category = $this->getCategory())
		{
			$model = $this->bootComponent('com_content')->getMVCFactory()
				->createModel('Articles', 'Site', ['ignore_request' => true]);
			$model->setState('params', Factory::getApplication()->getParams());
			$model->setState('filter.category_id', $category->id);
			$model->setState('filter.published', $this->getState('filter.published'));
			$model->setState('filter.access', $this->getState('filter.access'));
			$model->setState('filter.language', $this->getState('filter.language'));
			$model->setState('filter.featured', $this->getState('filter.featured'));
			$model->setState('list.ordering', $this->_buildContentOrderBy());
			$model->setState('list.start', $this->getState('list.start'));
			$model->setState('list.limit', $limit);
			$model->setState('list.direction', $this->getState('list.direction'));
			$model->setState('list.filter', $this->getState('list.filter'));
			$model->setState('filter.tag', $this->getState('filter.tag'));

			// Filter.subcategories indicates whether to include articles from subcategories in the list or blog
			$model->setState('filter.subcategories', $this->getState('filter.subcategories'));
			$model->setState('filter.max_category_levels', $this->getState('filter.max_category_levels'));
			$model->setState('list.links', $this->getState('list.links'));

            //Arkadiy start
            $model->setState('filter.article_id.include', $this->getState('filter.article_id.include'));
            $model->setState('filter.article_id', $this->getState('filter.article_id'));
            //Arkadiy end

			if ($limit >= 0)
			{
				$this->_articles = $model->getItems();

				if ($this->_articles === false)
				{
					$this->setError($model->getError());
				}
			}
			else
			{
				$this->_articles = array();
			}

			$this->_pagination = $model->getPagination();
		}

		return $this->_articles;
	}

	/**
	 * Build the orderby for the query
	 *
	 * @return  string	$orderby portion of query
	 *
	 * @since   1.5
	 */
	protected function _buildContentOrderBy()
	{
		$app       = Factory::getApplication();
		$db        = $this->getDbo();
		$params    = $this->state->params;
		$itemid    = $app->input->get('id', 0, 'int') . ':' . $app->input->get('Itemid', 0, 'int');
		$orderCol  = $app->getUserStateFromRequest('com_content.category.list.' . $itemid . '.filter_order', 'filter_order', '', 'string');
		$orderDirn = $app->getUserStateFromRequest('com_content.category.list.' . $itemid . '.filter_order_Dir', 'filter_order_Dir', '', 'cmd');
		$orderby   = ' ';

		if (!in_array($orderCol, $this->filter_fields))
		{
			$orderCol = null;
		}

		if (!in_array(strtoupper($orderDirn), array('ASC', 'DESC', '')))
		{
			$orderDirn = 'ASC';
		}

		if ($orderCol && $orderDirn)
		{
			$orderby .= $db->escape($orderCol) . ' ' . $db->escape($orderDirn) . ', ';
		}

		$articleOrderby   = $params->get('orderby_sec', 'rdate');
		$articleOrderDate = $params->get('order_date');
		$categoryOrderby  = $params->def('orderby_pri', '');
		$secondary        = QueryHelper::orderbySecondary($articleOrderby, $articleOrderDate, $this->getDbo()) . ', ';
		$primary          = QueryHelper::orderbyPrimary($categoryOrderby);

		$orderby .= $primary . ' ' . $secondary . ' a.created ';

		return $orderby;
	}

	/**
	 * Method to get a JPagination object for the data set.
	 *
	 * @return  \JPagination  A JPagination object for the data set.
	 *
	 * @since   3.0.1
	 */
	public function getPagination()
	{
		if (empty($this->_pagination))
		{
			return null;
		}

		return $this->_pagination;
	}

	/**
	 * Method to get category data for the current category
	 *
	 * @return  object
	 *
	 * @since   1.5
	 */
	public function getCategory()
	{
		if (!is_object($this->_item))
		{
			if (isset($this->state->params))
			{
				$params = $this->state->params;
				$options = array();
				$options['countItems'] = $params->get('show_cat_num_articles', 1) || !$params->get('show_empty_categories_cat', 0);
				$options['access']     = $params->get('check_access_rights', 1);
			}
			else
			{
				$options['countItems'] = 0;
			}

			$categories = Categories::getInstance('Content', $options);
			$this->_item = $categories->get($this->getState('category.id', 'root'));

			// Compute selected asset permissions.
			if (is_object($this->_item))
			{
				$user  = Factory::getUser();
				$asset = 'com_content.category.' . $this->_item->id;

				// Check general create permission.
				if ($user->authorise('core.create', $asset))
				{
					$this->_item->getParams()->set('access-create', true);
				}

				// TODO: Why aren't we lazy loading the children and siblings?
				$this->_children = $this->_item->getChildren();
				$this->_parent = false;

				if ($this->_item->getParent())
				{
					$this->_parent = $this->_item->getParent();
				}

				$this->_rightsibling = $this->_item->getSibling();
				$this->_leftsibling = $this->_item->getSibling(false);
			}
			else
			{
				$this->_children = false;
				$this->_parent = false;
			}
		}

		return $this->_item;
	}

	/**
	 * Get the parent category.
	 *
	 * @return  mixed  An array of categories or false if an error occurs.
	 *
	 * @since   1.6
	 */
	public function getParent()
	{
		if (!is_object($this->_item))
		{
			$this->getCategory();
		}

		return $this->_parent;
	}

	/**
	 * Get the left sibling (adjacent) categories.
	 *
	 * @return  mixed  An array of categories or false if an error occurs.
	 *
	 * @since   1.6
	 */
	public function &getLeftSibling()
	{
		if (!is_object($this->_item))
		{
			$this->getCategory();
		}

		return $this->_leftsibling;
	}

	/**
	 * Get the right sibling (adjacent) categories.
	 *
	 * @return  mixed  An array of categories or false if an error occurs.
	 *
	 * @since   1.6
	 */
	public function &getRightSibling()
	{
		if (!is_object($this->_item))
		{
			$this->getCategory();
		}

		return $this->_rightsibling;
	}

	/**
	 * Get the child categories.
	 *
	 * @return  mixed  An array of categories or false if an error occurs.
	 *
	 * @since   1.6
	 */
	public function &getChildren()
	{
		if (!is_object($this->_item))
		{
			$this->getCategory();
		}

		// Order subcategories
		if ($this->_children)
		{
			$params = $this->getState()->get('params');

			$orderByPri = $params->get('orderby_pri');

			if ($orderByPri === 'alpha' || $orderByPri === 'ralpha')
			{
				$this->_children = ArrayHelper::sortObjects($this->_children, 'title', ($orderByPri === 'alpha') ? 1 : (-1));
			}
		}

		return $this->_children;
	}

	/**
	 * Increment the hit counter for the category.
	 *
	 * @param   int  $pk  Optional primary key of the category to increment.
	 *
	 * @return  boolean True if successful; false otherwise and internal error set.
	 */
	public function hit($pk = 0)
	{
		$input = Factory::getApplication()->input;
		$hitcount = $input->getInt('hitcount', 1);

		if ($hitcount)
		{
			$pk = (!empty($pk)) ? $pk : (int) $this->getState('category.id');

			$table = Table::getInstance('Category', 'JTable');
			$table->hit($pk);
		}

		return true;
	}
}
