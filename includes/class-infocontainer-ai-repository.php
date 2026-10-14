<?php
/** Persistence and concurrency control for AI runs and human reviews. */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class InfoContainer_AI_Repository {
	private $db; private $runs; private $reviews; private $users;
	public function __construct( $database = null ) { if ( null === $database ) { global $wpdb; $database = $wpdb; } $this->db = $database; $this->runs = $database->prefix . 'infocontainer_ai_runs'; $this->reviews = $database->prefix . 'infocontainer_ai_reviews'; $this->users = $database->users; }

	/** Atomically reserve one provider call and enforce a per-user daily quota. */
	public function reserve( array $record ) {
		$old = $this->db->suppress_errors( true );
		try {
			if ( false === $this->db->query( 'START TRANSACTION' ) ) return $this->internal();
			$user = (int) get_current_user_id();
			$this->db->get_var( $this->db->prepare( "SELECT ID FROM {$this->users} WHERE ID=%d FOR UPDATE", $user ) );
			if ( $this->db->last_error ) { $this->db->query( 'ROLLBACK' ); return $this->internal(); }
			$existing = $this->find_by_request( $record['operation'], $record['client_request_id'] );
			if ( is_wp_error( $existing ) ) { $this->db->query( 'ROLLBACK' ); return $existing; }
			if ( is_array( $existing ) ) {
				$this->db->query( 'COMMIT' );
				if ( ! hash_equals( $existing['input_fingerprint'], $record['input_fingerprint'] ) ) return new WP_Error( 'infocontainer_ai_idempotency_conflict', __( 'Ese client_request_id ya se uso con entradas diferentes.', 'infocontainer' ), array( 'status' => 409 ) );
				return array( 'created' => false, 'run' => $existing );
			}
			$limit = max( 1, min( 1000, (int) apply_filters( 'infocontainer_ai_daily_quota', defined( 'INFOCONTAINER_AI_DAILY_QUOTA' ) ? INFOCONTAINER_AI_DAILY_QUOTA : 20, $user ) ) );
			$since = gmdate( 'Y-m-d 00:00:00' );
			$count = $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM {$this->runs} WHERE created_by=%d AND created_at_utc >= %s", $user, $since ) );
			if ( null === $count || $this->db->last_error ) { $this->db->query( 'ROLLBACK' ); return $this->internal(); }
			if ( (int) $count >= $limit ) { $this->db->query( 'ROLLBACK' ); return new WP_Error( 'infocontainer_ai_quota', __( 'Se alcanzo la cuota diaria de solicitudes de IA.', 'infocontainer' ), array( 'status' => 429, 'retry_after' => gmdate( 'Y-m-d\T00:00:00\Z', time() + DAY_IN_SECONDS ) ) ); }
			$now = current_time( 'mysql', true );
			$values = array( 'operation' => $record['operation'], 'inspection_id' => $record['inspection_id'], 'client_request_id' => $record['client_request_id'], 'created_by' => $user, 'created_at_utc' => $now, 'updated_at_utc' => $now, 'status' => 'processing', 'processing_expires_at_utc' => gmdate( 'Y-m-d H:i:s', time() + max( 60, min( 900, (int) apply_filters( 'infocontainer_ai_processing_ttl', 180 ) ) ) ), 'model_configured' => $record['model_configured'], 'model_used' => '', 'prompt_version' => InfoContainer_AI_Schemas::PROMPT_VERSION, 'input_fingerprint' => $record['input_fingerprint'], 'input_snapshot' => wp_json_encode( $record['input_snapshot'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), 'source_fingerprint' => $record['source_fingerprint'], 'proposal' => null, 'usage_json' => null, 'provider_response_id' => '', 'error_code' => '', 'error_message' => '', 'completed_at_utc' => null );
			$ok = $this->db->insert( $this->runs, $values, array( '%s','%d','%s','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s' ) );
			if ( 1 !== (int) $ok ) { $this->db->query( 'ROLLBACK' ); return $this->internal(); }
			$id = (int) $this->db->insert_id;
			if ( false === $this->db->query( 'COMMIT' ) ) { $this->db->query( 'ROLLBACK' ); return $this->internal(); }
			$run = $this->get( $id ); return is_wp_error( $run ) ? $run : array( 'created' => true, 'run' => $run );
		} catch ( Throwable $e ) { $this->db->query( 'ROLLBACK' ); return $this->internal(); } finally { $this->db->suppress_errors( $old ); }
	}

	public function complete( $id, array $result, array $proposal ) { return $this->finish( $id, 'completed', array( 'proposal' => wp_json_encode( $proposal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), 'usage_json' => wp_json_encode( $result['usage'], JSON_UNESCAPED_SLASHES ), 'provider_response_id' => $result['provider_response_id'], 'model_used' => $result['model_used'], 'error_code' => '', 'error_message' => '' ) ); }
	public function fail( $id, WP_Error $error ) {
		$data = $error->get_error_data(); $allowed = array( 'infocontainer_ai_not_configured', 'infocontainer_ai_timeout_unknown', 'infocontainer_ai_provider_transport', 'infocontainer_ai_provider_error', 'infocontainer_ai_provider_incomplete', 'infocontainer_ai_provider_refusal', 'infocontainer_ai_provider_empty', 'infocontainer_ai_provider_invalid_json', 'infocontainer_ai_invalid_output', 'infocontainer_ai_image_read' );
		$code = in_array( $error->get_error_code(), $allowed, true ) ? $error->get_error_code() : 'infocontainer_ai_failed';
		$status = 'infocontainer_ai_timeout_unknown' === $code ? 'unknown' : 'failed';
		return $this->finish( $id, $status, array( 'proposal' => null, 'usage_json' => isset( $data['usage'] ) ? wp_json_encode( $data['usage'] ) : null, 'provider_response_id' => isset( $data['provider_response_id'] ) ? $data['provider_response_id'] : '', 'model_used' => isset( $data['model_used'] ) ? $data['model_used'] : '', 'error_code' => $code, 'error_message' => substr( $error->get_error_message(), 0, 255 ) ) );
	}
	private function finish( $id, $status, array $data ) { $data['status']=$status; $data['processing_expires_at_utc']=null; $data['updated_at_utc']=current_time('mysql',true); $data['completed_at_utc']=$data['updated_at_utc']; $ok=$this->db->update($this->runs,$data,array('id'=>(int)$id),array('%s','%s','%s','%s','%s','%s','%s','%s','%s'),array('%d')); return false === $ok ? $this->internal() : $this->get($id); }

	public function get( $id ) { $row=$this->db->get_row($this->db->prepare("SELECT * FROM {$this->runs} WHERE id=%d",$id),ARRAY_A); return $this->db->last_error ? $this->internal() : (is_array($row)?$row:new WP_Error('infocontainer_ai_run_not_found',__('La ejecucion de IA no existe.','infocontainer'),array('status'=>404))); }
	public function find_by_request( $operation, $uuid ) { $row=$this->db->get_row($this->db->prepare("SELECT * FROM {$this->runs} WHERE operation=%s AND client_request_id=%s",$operation,$uuid),ARRAY_A); return $this->db->last_error ? $this->internal() : (is_array($row)?$row:null); }
	public function list_for_inspection( $inspection_id, $page, $per_page ) { $offset=($page-1)*$per_page; $total=$this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->runs} WHERE inspection_id=%d",$inspection_id)); $items=$this->db->get_results($this->db->prepare("SELECT * FROM {$this->runs} WHERE inspection_id=%d ORDER BY id DESC LIMIT %d OFFSET %d",$inspection_id,$per_page,$offset),ARRAY_A); return (null===$total||!is_array($items)||$this->db->last_error)?$this->internal():array('items'=>$items,'total'=>(int)$total); }
	public function list_reviews( $run_id ) { $rows=$this->db->get_results($this->db->prepare("SELECT * FROM {$this->reviews} WHERE run_id=%d ORDER BY id ASC",$run_id),ARRAY_A); return is_array($rows)&&!$this->db->last_error?$rows:$this->internal(); }
	public function add_review( $run_id, $client_id, $action, $content, $source_fingerprint ) {
		$existing=$this->db->get_row($this->db->prepare("SELECT * FROM {$this->reviews} WHERE run_id=%d AND client_review_id=%s",$run_id,$client_id),ARRAY_A);
		$encoded=null===$content?null:wp_json_encode($content,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
		if(is_array($existing)){ if($existing['action']!==$action||$existing['reviewed_content']!==$encoded)return new WP_Error('infocontainer_ai_review_conflict',__('Ese client_review_id ya se uso con contenido diferente.','infocontainer'),array('status'=>409)); return array('created'=>false,'review'=>$existing); }
		$ok=$this->db->insert($this->reviews,array('run_id'=>(int)$run_id,'client_review_id'=>$client_id,'reviewed_by'=>(int)get_current_user_id(),'action'=>$action,'reviewed_content'=>$encoded,'source_fingerprint'=>$source_fingerprint,'created_at_utc'=>current_time('mysql',true)),array('%d','%s','%d','%s','%s','%s','%s'));
		if(1!==(int)$ok){ $again=$this->db->get_row($this->db->prepare("SELECT * FROM {$this->reviews} WHERE run_id=%d AND client_review_id=%s",$run_id,$client_id),ARRAY_A); if(is_array($again)&&$again['action']===$action&&$again['reviewed_content']===$encoded)return array('created'=>false,'review'=>$again); return $this->db->last_error?new WP_Error('infocontainer_ai_review_conflict',__('No se pudo registrar la revision de forma idempotente.','infocontainer'),array('status'=>409)):$this->internal(); }
		$row=$this->db->get_row($this->db->prepare("SELECT * FROM {$this->reviews} WHERE id=%d",$this->db->insert_id),ARRAY_A); return array('created'=>true,'review'=>$row);
	}
	private function internal(){return new WP_Error('infocontainer_ai_internal',__('No se pudo completar la operacion de IA.','infocontainer'),array('status'=>500));}
}
