-- Remates Judiciales - Bogotá
-- Schema v3

CREATE TABLE IF NOT EXISTS publicaciones (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    article_id     VARCHAR(50) NOT NULL UNIQUE,
    titulo         TEXT,
    despacho       VARCHAR(1000),
    fecha_pub_portal  VARCHAR(30),
    fecha_pub_date DATE NULL,
    enlace         TEXT,
    tipo_contenido VARCHAR(30) DEFAULT 'pendiente'
                   COMMENT 'pendiente | simple | multiple | pdf_only | texto | desconocido',
    resumen_texto  TEXT,
    scraped_at     DATETIME NULL,
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tipo (tipo_contenido),
    INDEX idx_scraped (scraped_at),
    INDEX idx_fecha_pub (fecha_pub_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS remates (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    publicacion_id  INT NOT NULL,
    fecha_audiencia      VARCHAR(150),
    fecha_audiencia_date DATE NULL,
    hora                 VARCHAR(60),
    proceso_numero  VARCHAR(300),
    proceso_link    TEXT,
    clase           VARCHAR(200),
    bien_descripcion VARCHAR(600),
    demandante      VARCHAR(600),
    demandado       VARCHAR(600),
    estado_audiencia VARCHAR(150),
    modalidad       VARCHAR(150),
    acceso_subasta_url TEXT,
    avaluo          VARCHAR(300),
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (publicacion_id) REFERENCES publicaciones(id) ON DELETE CASCADE,
    INDEX idx_proceso (proceso_numero(50)),
    INDEX idx_demandante (demandante(100)),
    INDEX idx_demandado (demandado(100)),
    INDEX idx_fecha (fecha_audiencia(30))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS remates_vehiculos (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    article_id   VARCHAR(50)  NOT NULL,
    titulo_pub   TEXT,
    radicado     VARCHAR(300) NOT NULL,
    marca        VARCHAR(100),
    modelo       VARCHAR(100),
    anio         VARCHAR(10),
    placa        VARCHAR(20),
    color        VARCHAR(50),
    avaluo       VARCHAR(100),
    base_remate  VARCHAR(100),
    fecha_remate VARCHAR(100),
    hora_remate  VARCHAR(50),
    modalidad    VARCHAR(50),
    notas        TEXT,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_article (article_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS procesados (
    article_id   VARCHAR(50) NOT NULL PRIMARY KEY,
    procesado_at DATETIME    DEFAULT CURRENT_TIMESTAMP,
    notas        TEXT        NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
