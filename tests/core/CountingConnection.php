<?php
/**
 * Connection wrapper that counts statements — to prove a page costs the same number of queries
 * whatever the number of products.
 */

use DWCatalog\Db\Connection;
use DWCatalog\Db\Dialect;

class CountingConnection implements Connection {

	/** @var Connection */
	private $db;
	public $count = 0;

	public function __construct( Connection $db ) {
		$this->db = $db;
	}

	public function select( string $sql, array $params = array() ): array {
		$this->count++;
		return $this->db->select( $sql, $params );
	}

	public function selectRow( string $sql, array $params = array() ): ?array {
		$this->count++;
		return $this->db->selectRow( $sql, $params );
	}

	public function selectValue( string $sql, array $params = array() ) {
		$this->count++;
		return $this->db->selectValue( $sql, $params );
	}

	public function selectColumn( string $sql, array $params = array() ): array {
		$this->count++;
		return $this->db->selectColumn( $sql, $params );
	}

	public function execute( string $sql, array $params = array() ): int {
		$this->count++;
		return $this->db->execute( $sql, $params );
	}

	public function insert( string $table, array $row ): int {
		$this->count++;
		return $this->db->insert( $table, $row );
	}

	public function insertMany( string $table, array $columns, array $rows ): void {
		$this->count++;
		$this->db->insertMany( $table, $columns, $rows );
	}

	public function transaction( callable $fn ) {
		return $this->db->transaction( $fn );
	}

	public function table( string $name ): string {
		return $this->db->table( $name );
	}

	public function dialect(): Dialect {
		return $this->db->dialect();
	}

	public function tableExists( string $name ): bool {
		return $this->db->tableExists( $name );
	}

	public function columnExists( string $table, string $column ): bool {
		return $this->db->columnExists( $table, $column );
	}
}
