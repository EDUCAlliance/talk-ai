<?php

declare(strict_types=1);

namespace OCA\EducAI\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Settings>
 */
class SettingsMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'educai_settings', Settings::class);
	}

	/**
	 * Get the global settings (always ID = 1)
	 *
	 * @return Settings
	 * @throws DoesNotExistException
	 */
	public function getSettings(): Settings {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('id', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
			);

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			// Create default settings if they don't exist
			$settings = new Settings();
			$settings->setId(1);
			$settings->setApiProvider('custom');
			$settings->setDefaultModel('llama-3.3-70b-instruct');
			$settings->setDefaultTemperature(0.2);
			$settings->setUpdatedAt(time());
			return $this->insert($settings);
		}
	}
}

