-- Esquema inicial consolidado del servidor de licencias de AD-EL POS.
-- Equivale al esquema final de la cadena heredada 001-007, sin sus datos ni sus pasos de historia.
-- Endurecimiento registrado: license_credentials.credential_encrypted y code_format son NOT NULL.
-- El orden de tablas, columnas, índices y llaves foráneas es el de la cadena heredada: los nombres
-- que MySQL asigna a las llaves foráneas sin nombre dependen de ese orden.
-- Reformateado en la etapa 3 con la regla de código legible: el esquema que produce no cambió.
-- Este archivo no debe contener punto y coma al final de una línea de comentario.
CREATE TABLE security_state (
	id TINYINT PRIMARY KEY
) ENGINE=InnoDB;
INSERT INTO security_state VALUES (1);
CREATE TABLE admin_users (
	id BINARY(16) PRIMARY KEY,
	login VARCHAR(190) NOT NULL UNIQUE,
	display_name VARCHAR(190) NOT NULL,
	password_hash VARCHAR(255) NOT NULL,
	role VARCHAR(24) NOT NULL,
	state VARCHAR(16) NOT NULL DEFAULT 'active',
	mfa_secret_encrypted TEXT NULL,
	last_totp_step BIGINT NULL,
	created_at DATETIME(6) NOT NULL,
	updated_at DATETIME(6) NOT NULL,
	CONSTRAINT chk_admin_role CHECK (role IN ('superadmin','operator','viewer')),
	CONSTRAINT chk_admin_state CHECK (state IN ('active','disabled'))
) ENGINE=InnoDB;
CREATE TABLE admin_sessions (
	token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
	admin_id BINARY(16) NULL,
	stage VARCHAR(16) NOT NULL,
	enrollment_secret TEXT NULL,
	created_at DATETIME(6) NOT NULL,
	last_seen_at DATETIME(6) NOT NULL,
	expires_at DATETIME(6) NOT NULL,
	mfa_verified_at DATETIME(6) NULL,
	revoked_at DATETIME(6) NULL,
	trust_offered_at DATETIME(6) NULL,
	FOREIGN KEY (admin_id) REFERENCES admin_users(id),
	INDEX ix_session_expiry(expires_at),
	CONSTRAINT chk_session_stage CHECK (stage IN ('anonymous','pending','authenticated')),
	CONSTRAINT chk_session_auth CHECK (stage='anonymous' OR admin_id IS NOT NULL)
) ENGINE=InnoDB;
CREATE TABLE admin_recovery_codes (
	id BINARY(16) PRIMARY KEY,
	admin_id BINARY(16) NOT NULL,
	code_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	used_at DATETIME(6) NULL,
	FOREIGN KEY (admin_id) REFERENCES admin_users(id),
	UNIQUE KEY uq_recovery(admin_id,code_hash)
) ENGINE=InnoDB;
CREATE TABLE rate_limit_buckets (
	scope VARCHAR(32) CHARACTER SET ascii NOT NULL,
	key_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	window_start BIGINT NOT NULL,
	count INT UNSIGNED NOT NULL,
	PRIMARY KEY(scope,key_digest,window_start)
) ENGINE=InnoDB;
CREATE TABLE audit_heads (
	stream_id VARCHAR(16) PRIMARY KEY,
	last_sequence BIGINT UNSIGNED NOT NULL,
	last_event_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
) ENGINE=InnoDB;
INSERT INTO audit_heads VALUES ('admin',0,REPEAT('0',64));
CREATE TABLE audit_events (
	sequence BIGINT UNSIGNED PRIMARY KEY,
	occurred_at DATETIME(6) NOT NULL,
	actor_id BINARY(16) NULL,
	operation VARCHAR(64) NOT NULL,
	target_type VARCHAR(32) NOT NULL,
	target_id VARCHAR(190) NULL,
	payload_json LONGTEXT NOT NULL,
	previous_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	event_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	CONSTRAINT chk_audit_json CHECK (JSON_VALID(payload_json)),
	INDEX ix_audit_time(occurred_at),
	INDEX ix_audit_target(target_type,target_id),
	INDEX ix_audit_actor(actor_id),
	INDEX ix_audit_operation(operation)
) ENGINE=InnoDB;
CREATE TABLE admin_operations (
	operation_id BINARY(16) PRIMARY KEY,
	admin_id BINARY(16) NOT NULL,
	action VARCHAR(64) NOT NULL,
	input_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	result_json JSON NULL,
	created_at DATETIME(6) NOT NULL,
	FOREIGN KEY (admin_id) REFERENCES admin_users(id)
) ENGINE=InnoDB;
CREATE TABLE customers (
	id BINARY(16) PRIMARY KEY,
	display_name VARCHAR(190) NOT NULL,
	legal_name VARCHAR(190) NULL,
	state VARCHAR(16) NOT NULL DEFAULT 'active',
	row_version INT UNSIGNED NOT NULL DEFAULT 1,
	created_at DATETIME(6) NOT NULL,
	updated_at DATETIME(6) NOT NULL,
	tax_id VARCHAR(32) NOT NULL DEFAULT '',
	CONSTRAINT chk_customer_state CHECK (state IN ('active','archived')),
	INDEX ix_customer_name(display_name)
) ENGINE=InnoDB;
CREATE TABLE customer_contacts (
	id BINARY(16) PRIMARY KEY,
	customer_id BINARY(16) NOT NULL UNIQUE,
	name VARCHAR(190) NOT NULL,
	email VARCHAR(190) NULL,
	phone VARCHAR(60) NULL,
	FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB;
CREATE TABLE products (
	product_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
	display_name VARCHAR(190) NOT NULL,
	description VARCHAR(500) NOT NULL DEFAULT '',
	state VARCHAR(16) NOT NULL DEFAULT 'active',
	row_version INT UNSIGNED NOT NULL DEFAULT 1,
	created_at DATETIME(6) NOT NULL,
	updated_at DATETIME(6) NOT NULL,
	CONSTRAINT chk_product_state CHECK (state IN ('active','archived'))
) ENGINE=InnoDB;
CREATE TABLE product_capabilities (
	product_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	capability_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	display_name VARCHAR(190) NOT NULL,
	PRIMARY KEY(product_id,capability_key),
	FOREIGN KEY (product_id) REFERENCES products(product_id)
) ENGINE=InnoDB;
CREATE TABLE capability_dependencies (
	product_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	capability_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	required_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	PRIMARY KEY(product_id,capability_key,required_key),
	FOREIGN KEY(product_id,capability_key) REFERENCES product_capabilities(product_id,capability_key),
	FOREIGN KEY(product_id,required_key) REFERENCES product_capabilities(product_id,capability_key),
	CONSTRAINT chk_dependency_self CHECK (capability_key<>required_key)
) ENGINE=InnoDB;
CREATE TABLE licenses (
	license_id BINARY(16) PRIMARY KEY,
	customer_id BINARY(16) NOT NULL,
	product_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	license_type VARCHAR(16) NOT NULL,
	commercial_status VARCHAR(16) NOT NULL DEFAULT 'issued',
	expires_at DATETIME(6) NULL,
	grace_days TINYINT UNSIGNED NOT NULL,
	maintenance_until DATETIME(6) NULL,
	entitled_release_until DATETIME(6) NOT NULL,
	revision_counter BIGINT UNSIGNED NOT NULL DEFAULT 0,
	row_version INT UNSIGNED NOT NULL DEFAULT 1,
	created_at DATETIME(6) NOT NULL,
	updated_at DATETIME(6) NOT NULL,
	commercial_reference VARCHAR(190) NOT NULL DEFAULT '',
	authorization_note VARCHAR(500) NOT NULL DEFAULT '',
	administrative_version INT UNSIGNED NOT NULL DEFAULT 1,
	FOREIGN KEY(customer_id) REFERENCES customers(id),
	FOREIGN KEY(product_id) REFERENCES products(product_id),
	UNIQUE KEY uq_license_product(license_id,product_id),
	CONSTRAINT chk_license_status CHECK (commercial_status IN ('issued','revoked')),
	CONSTRAINT chk_license_terms CHECK (
		(
			license_type='perpetual'
			AND expires_at IS NULL
			AND grace_days=0
		)
		OR (
			license_type='subscription'
			AND expires_at IS NOT NULL
			AND grace_days=15
			AND maintenance_until IS NULL
		)
	),
	INDEX ix_license_customer(customer_id),
	INDEX ix_license_product_state(product_id,commercial_status),
	INDEX ix_license_expiry(expires_at)
) ENGINE=InnoDB;
CREATE TABLE license_features (
	license_id BINARY(16) NOT NULL,
	product_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	capability_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	enabled BOOLEAN NOT NULL,
	PRIMARY KEY(license_id,capability_key),
	FOREIGN KEY(license_id,product_id) REFERENCES licenses(license_id,product_id),
	FOREIGN KEY(product_id,capability_key) REFERENCES product_capabilities(product_id,capability_key),
	CONSTRAINT chk_feature_bool CHECK (enabled IN (0,1))
) ENGINE=InnoDB;
CREATE TABLE license_limits (
	license_id BINARY(16) PRIMARY KEY,
	max_installations INT NOT NULL DEFAULT 1,
	max_users INT NULL,
	max_libraries INT NULL,
	max_documents INT NULL,
	FOREIGN KEY(license_id) REFERENCES licenses(license_id),
	CONSTRAINT chk_limit_installations CHECK (max_installations=1),
	CONSTRAINT chk_limit_users CHECK (max_users IS NULL OR max_users>0),
	CONSTRAINT chk_limit_libraries CHECK (max_libraries IS NULL OR max_libraries>0),
	CONSTRAINT chk_limit_documents CHECK (max_documents IS NULL OR max_documents>0)
) ENGINE=InnoDB;
CREATE TABLE license_credentials (
	credential_id BINARY(16) PRIMARY KEY,
	license_id BINARY(16) NOT NULL,
	key_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	digest_key_version INT NOT NULL DEFAULT 1,
	state VARCHAR(16) NOT NULL,
	created_at DATETIME(6) NOT NULL,
	retired_at DATETIME(6) NULL,
	active_license_id BINARY(16) GENERATED ALWAYS AS (
		CASE WHEN state='active' THEN license_id ELSE NULL END
	) STORED,
	credential_encrypted TEXT NOT NULL,
	code_format VARCHAR(24) NOT NULL,
	UNIQUE KEY uq_active_credential(active_license_id),
	UNIQUE KEY uq_credential_digest(digest_key_version,key_digest),
	FOREIGN KEY(license_id) REFERENCES licenses(license_id),
	CONSTRAINT chk_credential_state CHECK (state IN ('active','retired'))
) ENGINE=InnoDB;
CREATE TABLE license_changes (
	id BINARY(16) PRIMARY KEY,
	license_id BINARY(16) NOT NULL,
	version INT UNSIGNED NOT NULL,
	action VARCHAR(64) NOT NULL,
	admin_id BINARY(16) NOT NULL,
	snapshot_json JSON NOT NULL,
	reason VARCHAR(500) NOT NULL,
	created_at DATETIME(6) NOT NULL,
	FOREIGN KEY(license_id) REFERENCES licenses(license_id),
	FOREIGN KEY(admin_id) REFERENCES admin_users(id),
	UNIQUE KEY uq_license_change(license_id,version)
) ENGINE=InnoDB;
CREATE TABLE subscription_periods (
	id BINARY(16) PRIMARY KEY,
	license_id BINARY(16) NOT NULL,
	previous_expires_at DATETIME(6) NULL,
	new_expires_at DATETIME(6) NOT NULL,
	effective_at DATETIME(6) NOT NULL,
	commercial_reference VARCHAR(190) NULL,
	admin_id BINARY(16) NOT NULL,
	reason VARCHAR(500) NOT NULL,
	FOREIGN KEY(license_id) REFERENCES licenses(license_id),
	FOREIGN KEY(admin_id) REFERENCES admin_users(id)
) ENGINE=InnoDB;
CREATE TABLE maintenance_purchases (
	id BINARY(16) PRIMARY KEY,
	license_id BINARY(16) NOT NULL,
	previous_until DATETIME(6) NULL,
	new_until DATETIME(6) NOT NULL,
	purchased_at DATETIME(6) NOT NULL,
	commercial_reference VARCHAR(190) NOT NULL,
	admin_id BINARY(16) NOT NULL,
	reason VARCHAR(500) NOT NULL,
	FOREIGN KEY(license_id) REFERENCES licenses(license_id),
	FOREIGN KEY(admin_id) REFERENCES admin_users(id)
) ENGINE=InnoDB;
CREATE TABLE signing_keys (
	kid VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
	environment VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	purpose VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	public_key BINARY(32) NOT NULL UNIQUE,
	private_ref VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
	state VARCHAR(16) NOT NULL DEFAULT 'staged',
	created_at DATETIME(6) NOT NULL,
	CONSTRAINT chk_signing_environment CHECK(environment IN ('production','development','testing')),
	CONSTRAINT chk_signing_purpose CHECK(purpose IN ('license','manifest')),
	CONSTRAINT chk_signing_state CHECK(state IN ('staged','signing','verify_only')),
	UNIQUE KEY uq_signing_scope_key(environment,purpose,kid)
) ENGINE=InnoDB;
CREATE TABLE signing_scopes (
	environment VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	purpose VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	active_kid VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
	PRIMARY KEY(environment,purpose),
	FOREIGN KEY(environment,purpose,active_kid) REFERENCES signing_keys(environment,purpose,kid)
) ENGINE=InnoDB;
CREATE TABLE activations (
	activation_id BINARY(16) PRIMARY KEY,
	license_id BINARY(16) NOT NULL,
	product_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	installation_id BINARY(16) NOT NULL,
	installation_public_key BINARY(32) NOT NULL,
	fingerprint_version VARCHAR(8) CHARACTER SET ascii NOT NULL,
	fingerprint_hash VARCHAR(71) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	state VARCHAR(16) NOT NULL,
	activated_at DATETIME(6) NOT NULL,
	ended_at DATETIME(6) NULL,
	current_revision_id BINARY(16) NULL,
	active_license_id BINARY(16) GENERATED ALWAYS AS (
		CASE WHEN state='active' THEN license_id ELSE NULL END
	) STORED,
	UNIQUE KEY uq_active_installation(active_license_id),
	UNIQUE KEY uq_activation_license(activation_id,license_id),
	FOREIGN KEY(license_id,product_id) REFERENCES licenses(license_id,product_id),
	CONSTRAINT chk_activation_state CHECK(state IN ('active','deactivated','revoked')),
	CONSTRAINT chk_activation_end CHECK(
		(
			state='active'
			AND ended_at IS NULL
		)
		OR (
			state<>'active'
			AND ended_at IS NOT NULL
		)
	),
	INDEX ix_activation_identity(product_id,installation_id)
) ENGINE=InnoDB;
CREATE TABLE license_revisions (
	revision_id BINARY(16) PRIMARY KEY,
	license_id BINARY(16) NOT NULL,
	activation_id BINARY(16) NOT NULL,
	license_revision BIGINT UNSIGNED NOT NULL,
	kid VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	license_status VARCHAR(16) NOT NULL,
	issued_at DATETIME(6) NOT NULL,
	payload_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
	license_jws TEXT CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	jws_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	FOREIGN KEY(activation_id,license_id) REFERENCES activations(activation_id,license_id),
	FOREIGN KEY(kid) REFERENCES signing_keys(kid),
	UNIQUE KEY uq_license_revision(license_id,license_revision),
	UNIQUE KEY uq_activation_revision(activation_id,revision_id),
	CONSTRAINT chk_revision_positive CHECK(license_revision>0),
	CONSTRAINT chk_revision_json CHECK(JSON_VALID(payload_json)),
	CONSTRAINT chk_revision_status CHECK(license_status IN ('active','revoked'))
) ENGINE=InnoDB;
ALTER TABLE activations
	ADD CONSTRAINT fk_current_revision
	FOREIGN KEY(activation_id,current_revision_id) REFERENCES license_revisions(activation_id,revision_id);
CREATE TABLE activation_challenges (
	challenge_id BINARY(16) PRIMARY KEY,
	action VARCHAR(16) NOT NULL,
	product_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	installation_id BINARY(16) NOT NULL,
	activation_id BINARY(16) NULL,
	nonce VARCHAR(43) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	created_at DATETIME(6) NOT NULL,
	expires_at DATETIME(6) NOT NULL,
	consumed_at DATETIME(6) NULL,
	consumed_request_id BINARY(16) NULL,
	CONSTRAINT chk_challenge_action CHECK(action IN ('activate','refresh','deactivate')),
	CONSTRAINT chk_challenge_identity CHECK(
		(
			action='activate'
			AND activation_id IS NULL
		)
		OR (
			action<>'activate'
			AND activation_id IS NOT NULL
		)
	),
	CONSTRAINT chk_challenge_consumption CHECK(
		(
			consumed_at IS NULL
			AND consumed_request_id IS NULL
		)
		OR (
			consumed_at IS NOT NULL
			AND consumed_request_id IS NOT NULL
		)
	),
	INDEX ix_challenge_expiry(expires_at)
) ENGINE=InnoDB;
CREATE TABLE license_requests (
	product_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	request_id BINARY(16) NOT NULL,
	channel VARCHAR(16) NOT NULL,
	action VARCHAR(16) NOT NULL,
	input_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	digest_key_version INT NOT NULL DEFAULT 1,
	installation_id BINARY(16) NOT NULL,
	activation_id BINARY(16) NULL,
	public_key BINARY(32) NOT NULL,
	proof_message TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
	response_status SMALLINT UNSIGNED NULL,
	response_body LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
	created_at DATETIME(6) NOT NULL,
	completed_at DATETIME(6) NULL,
	PRIMARY KEY(product_id,request_id),
	CONSTRAINT chk_request_channel CHECK(channel IN ('online','offline')),
	CONSTRAINT chk_request_response CHECK(
		(
			response_status IS NULL
			AND response_body IS NULL
			AND completed_at IS NULL
		)
		OR (
			response_status IS NOT NULL
			AND response_body IS NOT NULL
			AND completed_at IS NOT NULL
			AND JSON_VALID(response_body)
		)
	)
) ENGINE=InnoDB;
CREATE TABLE offline_requests (
	id BINARY(16) PRIMARY KEY,
	product_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	request_id BINARY(16) NOT NULL,
	evidence_encrypted MEDIUMTEXT CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	encryption_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
	projection_json LONGTEXT NOT NULL,
	imported_by BINARY(16) NOT NULL,
	imported_at DATETIME(6) NOT NULL,
	UNIQUE KEY uq_offline_request (product_id,request_id),
	KEY ix_offline_imported (imported_at),
	CONSTRAINT fk_offline_request
		FOREIGN KEY (product_id,request_id) REFERENCES license_requests(product_id,request_id),
	CONSTRAINT fk_offline_importer FOREIGN KEY (imported_by) REFERENCES admin_users(id),
	CONSTRAINT ck_offline_projection CHECK (JSON_VALID(projection_json)),
	CONSTRAINT ck_offline_encryption CHECK (encryption_version=1)
) ENGINE=InnoDB;
CREATE TABLE offline_decisions (
	offline_id BINARY(16) PRIMARY KEY,
	decision VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	license_id BINARY(16) NULL,
	revision_id BINARY(16) NULL,
	admin_id BINARY(16) NOT NULL,
	reason VARCHAR(500) NOT NULL,
	decided_at DATETIME(6) NOT NULL,
	CONSTRAINT fk_decision_offline FOREIGN KEY (offline_id) REFERENCES offline_requests(id),
	CONSTRAINT fk_decision_license FOREIGN KEY (license_id) REFERENCES licenses(license_id),
	CONSTRAINT fk_decision_revision FOREIGN KEY (revision_id) REFERENCES license_revisions(revision_id),
	CONSTRAINT fk_decision_admin FOREIGN KEY (admin_id) REFERENCES admin_users(id),
	CONSTRAINT ck_offline_decision CHECK (
		(
			decision='approved'
			AND license_id IS NOT NULL
			AND revision_id IS NOT NULL
		)
		OR (
			decision='rejected'
			AND license_id IS NULL
			AND revision_id IS NULL
		)
	)
) ENGINE=InnoDB;
CREATE TABLE license_transfers (
	id BINARY(16) PRIMARY KEY,
	license_id BINARY(16) NOT NULL,
	outgoing_activation_id BINARY(16) NOT NULL,
	incoming_activation_id BINARY(16) NULL,
	admin_id BINARY(16) NOT NULL,
	reason VARCHAR(500) NOT NULL,
	offline_limit_accepted BOOLEAN NOT NULL,
	created_at DATETIME(6) NOT NULL,
	UNIQUE KEY uq_transfer_outgoing (outgoing_activation_id),
	CONSTRAINT fk_transfer_license FOREIGN KEY (license_id) REFERENCES licenses(license_id),
	CONSTRAINT fk_transfer_outgoing
		FOREIGN KEY (outgoing_activation_id,license_id) REFERENCES activations(activation_id,license_id),
	CONSTRAINT fk_transfer_incoming
		FOREIGN KEY (incoming_activation_id,license_id) REFERENCES activations(activation_id,license_id),
	CONSTRAINT fk_transfer_admin FOREIGN KEY (admin_id) REFERENCES admin_users(id),
	CONSTRAINT ck_transfer_ack CHECK (offline_limit_accepted=1),
	CONSTRAINT ck_transfer_distinct CHECK (
		incoming_activation_id IS NULL
		OR incoming_activation_id<>outgoing_activation_id
	)
) ENGINE=InnoDB;
CREATE TABLE admin_permission_grants (
	admin_id BINARY(16) NOT NULL,
	permission VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	PRIMARY KEY(admin_id,permission),
	FOREIGN KEY(admin_id) REFERENCES admin_users(id)
) ENGINE=InnoDB;
CREATE TABLE license_administrative_changes (
	id BINARY(16) PRIMARY KEY,
	license_id BINARY(16) NOT NULL,
	version INT UNSIGNED NOT NULL,
	field_name VARCHAR(32) NOT NULL,
	old_value VARCHAR(500) NOT NULL,
	new_value VARCHAR(500) NOT NULL,
	admin_id BINARY(16) NOT NULL,
	created_at DATETIME(6) NOT NULL,
	FOREIGN KEY(license_id) REFERENCES licenses(license_id),
	FOREIGN KEY(admin_id) REFERENCES admin_users(id),
	UNIQUE KEY uq_administrative_revision(license_id,version,field_name)
) ENGINE=InnoDB;
CREATE TABLE license_documents (
	id BINARY(16) PRIMARY KEY,
	license_id BINARY(16) NOT NULL,
	document_type VARCHAR(24) NOT NULL,
	revision INT UNSIGNED NOT NULL,
	folio VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
	verification_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
	public_json JSON NOT NULL,
	snapshot_encrypted MEDIUMTEXT NOT NULL,
	pdf_encrypted MEDIUMTEXT NOT NULL,
	pdf_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	credential_id BINARY(16) NULL,
	offline_revision_id BINARY(16) NULL,
	generated_by BINARY(16) NOT NULL,
	generated_at DATETIME(6) NOT NULL,
	UNIQUE KEY uq_document_revision(license_id,document_type,revision),
	FOREIGN KEY(license_id) REFERENCES licenses(license_id),
	FOREIGN KEY(generated_by) REFERENCES admin_users(id),
	FOREIGN KEY(credential_id) REFERENCES license_credentials(credential_id),
	FOREIGN KEY(offline_revision_id) REFERENCES license_revisions(revision_id),
	CONSTRAINT chk_document_type CHECK (document_type IN ('licensing','delivery'))
) ENGINE=InnoDB;
CREATE TABLE admin_trusted_devices (
	id BINARY(16) PRIMARY KEY,
	admin_id BINARY(16) NOT NULL,
	token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
	credential_binding CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	description VARCHAR(190) NOT NULL,
	created_at DATETIME(6) NOT NULL,
	expires_at DATETIME(6) NOT NULL,
	last_used_at DATETIME(6) NULL,
	revoked_at DATETIME(6) NULL,
	FOREIGN KEY (admin_id) REFERENCES admin_users(id) ON DELETE CASCADE,
	INDEX ix_trusted_owner(admin_id,revoked_at,expires_at),
	CONSTRAINT chk_trusted_expiry CHECK (expires_at > created_at)
) ENGINE=InnoDB;
INSERT INTO products(product_id,display_name,description,state,created_at,updated_at)
VALUES ('adel_pos','AD-EL POS','Punto de venta para restaurantes','active',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6));
