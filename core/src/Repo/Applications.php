<?php
namespace DWCatalog\Repo;

use DWCatalog\Db\Connection;
use DWCatalog\Host\Host;
use DWCatalog\Support\Text;

/**
 * Wholesale applications. Row: id, status, email, company, name, phone, data (JSON: every form
 * field), files (JSON list), note, source, created_at, updated_at.
 *
 * Attachments are kept in the host's private directory under random names — never under a public
 * URL. Staff download them through the admin (customer screens: phase 5).
 */
class Applications {

	const STATUSES = array( 'new', 'info_requested', 'approved', 'rejected' );

	/** Extensions accepted as attachments. */
	const FILE_TYPES = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx' );
	const MAX_BYTES  = 15728640;

	/** @var Connection */
	private $db;
	/** @var Host */
	private $host;

	public function __construct( Connection $db, Host $host ) {
		$this->db   = $db;
		$this->host = $host;
	}

	public function count( string $status = '' ): int {
		if ( '' === $status ) {
			return (int) $this->db->selectValue( 'SELECT COUNT(*) FROM ' . $this->db->table( 'applications' ) );
		}
		return (int) $this->db->selectValue( 'SELECT COUNT(*) FROM ' . $this->db->table( 'applications' ) . ' WHERE status = ?', array( $status ) );
	}

	public function get( int $id ): ?array {
		$row = $this->db->selectRow( 'SELECT * FROM ' . $this->db->table( 'applications' ) . ' WHERE id = ?', array( $id ) );
		if ( $row ) {
			$row['data']  = self::decode( $row['data'] );
			$row['files'] = self::decode( $row['files'] );
		}
		return $row;
	}

	/**
	 * @param array $fields form fields, key => value (email / company_name / first_name / … are also
	 *                      copied to their own columns for lists and duplicate checks)
	 * @return int id
	 */
	public function create( array $fields, string $source = '', string $created = '', string $status = 'new' ): int {
		$clean = array();
		foreach ( $fields as $k => $v ) {
			$k = Text::key( $k );
			if ( '' !== $k && is_scalar( $v ) && '' !== trim( (string) $v ) ) {
				$clean[ $k ] = Text::block( (string) $v, 5000 );
			}
		}
		$pick = function ( array $keys ) use ( $clean ) {
			foreach ( $keys as $k ) {
				if ( isset( $clean[ $k ] ) ) {
					return $clean[ $k ];
				}
			}
			return '';
		};
		$name = trim( $pick( array( 'first_name' ) ) . ' ' . $pick( array( 'last_name' ) ) );
		$now  = Text::now();
		return $this->db->insert( 'applications', array(
			'status'     => in_array( $status, self::STATUSES, true ) ? $status : 'new',
			'email'      => Text::cut( Text::lower( $pick( array( 'email', 'email_address' ) ) ), 191 ),
			'company'    => Text::line( $pick( array( 'company_name', 'company', 'business_name' ) ), 200 ),
			'name'       => Text::line( '' !== $name ? $name : $pick( array( 'name', 'contact_name' ) ), 200 ),
			'phone'      => Text::line( $pick( array( 'phone', 'phone_number' ) ), 50 ),
			'data'       => json_encode( $clean, JSON_UNESCAPED_UNICODE ),
			'files'      => '[]',
			'note'       => '',
			'source'     => Text::line( $source, 40 ),
			'created_at' => preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $created ) ? $created : $now,
			'updated_at' => $now,
		) );
	}

	/**
	 * Copy a file into private storage and attach it. The original is left where it is.
	 * @return true|string true, or why it was refused
	 */
	public function attach( int $id, string $path, string $name ) {
		$row = $this->get( $id );
		if ( ! $row ) {
			return 'Application not found.';
		}
		$ext = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, self::FILE_TYPES, true ) ) {
			return 'This file type is not accepted.';
		}
		if ( ! is_file( $path ) || filesize( $path ) > self::MAX_BYTES ) {
			return 'The file is missing or too large (max 15 MB).';
		}
		$dir = $this->host->privateDir() . '/applications';
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0750, true ) && ! is_dir( $dir ) ) { // phpcs:ignore
			return 'Storage directory is not writable.';
		}
		// Random name without the real extension: the file cannot be guessed, or run, from a URL.
		$stored = bin2hex( random_bytes( 20 ) ) . '.bin';
		if ( ! @copy( $path, $dir . '/' . $stored ) ) { // phpcs:ignore
			return 'Could not store the file.';
		}
		$files   = $row['files'];
		$files[] = array( 'file' => $stored, 'name' => Text::line( basename( $name ), 200 ), 'ext' => $ext, 'size' => (int) filesize( $dir . '/' . $stored ) );
		$this->db->execute( 'UPDATE ' . $this->db->table( 'applications' ) . ' SET files = ?, updated_at = ? WHERE id = ?', array( json_encode( $files, JSON_UNESCAPED_UNICODE ), Text::now(), $id ) );
		return true;
	}

	/** Absolute path of a stored attachment, or '' when it is not one of this application's files. */
	public function filePath( int $id, string $stored ): string {
		$row = $this->get( $id );
		foreach ( $row ? $row['files'] : array() as $f ) {
			if ( isset( $f['file'] ) && $f['file'] === $stored && preg_match( '/^[a-f0-9]{40}\.bin$/', $stored ) ) {
				return $this->host->privateDir() . '/applications/' . $stored;
			}
		}
		return '';
	}

	public function delete( int $id ): void {
		$row = $this->get( $id );
		if ( ! $row ) {
			return;
		}
		foreach ( $row['files'] as $f ) {
			$path = isset( $f['file'] ) ? $this->filePath( $id, (string) $f['file'] ) : '';
			if ( '' !== $path && is_file( $path ) ) {
				@unlink( $path ); // phpcs:ignore
			}
		}
		$this->db->execute( 'DELETE FROM ' . $this->db->table( 'applications' ) . ' WHERE id = ?', array( $id ) );
	}

	private static function decode( $json ): array {
		$v = json_decode( (string) $json, true );
		return is_array( $v ) ? $v : array();
	}
}
