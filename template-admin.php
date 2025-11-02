<div class="wrap ipvt-wrap">
    <h1>📊 IP Visitor Tracker</h1>
    
    <div class="ipvt-stats">
        <div class="stat-box">
            <h3>Total Visitas</h3>
            <p class="stat-number"><?php echo number_format($total_visits); ?></p>
        </div>
        <div class="stat-box">
            <h3>IPs Únicas</h3>
            <p class="stat-number"><?php echo number_format($unique_ips); ?></p>
        </div>
        <div class="stat-box">
            <h3>Visitas Hoy</h3>
            <p class="stat-number"><?php echo number_format($today_visits); ?></p>
        </div>
        <div class="stat-box alert">
            <h3>IPs Sospechosas</h3>
            <p class="stat-number"><?php echo count($suspicious_ips); ?></p>
        </div>
    </div>
    
    <?php if (!empty($suspicious_ips)): ?>
    <div class="ipvt-alert">
        <h3>⚠️ IPs Sospechosas (últimas 24h)</h3>
        <table class="wp-list-table widefat">
            <thead>
                <tr>
                    <th>IP</th>
                    <th>Visitas</th>
                    <th>Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($suspicious_ips as $ip): ?>
                <tr>
                    <td><strong><?php echo esc_html($ip->ip_address); ?></strong></td>
                    <td><span class="badge-danger"><?php echo $ip->visit_count; ?> visitas</span></td>
                    <td><button class="button filter-ip" data-ip="<?php echo esc_attr($ip->ip_address); ?>">Ver Detalles</button></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <div class="ipvt-filters">
        <h3>Filtros</h3>
        <form id="filter-form">
            <input type="text" id="search_ip" placeholder="Buscar IP">
            <input type="date" id="date_from" placeholder="Desde">
            <input type="date" id="date_to" placeholder="Hasta">
            <button type="submit" class="button button-primary">Filtrar</button>
            <button type="button" id="reset-filters" class="button">Reset</button>
        </form>
    </div>
    
    <div class="ipvt-actions">
        <button id="export-csv" class="button">📥 Exportar CSV</button>
        <button id="refresh" class="button">🔄 Actualizar</button>
    </div>
    
    <div id="loading" style="display:none;">Cargando...</div>
    
    <table class="wp-list-table widefat striped">
        <thead>
            <tr>
                <th>IP</th>
                <th>Fecha y Hora</th>
                <th>Página</th>
                <th>Referrer</th>
                <th>UTM Source</th>
                <th>UTM Campaign</th>
            </tr>
        </thead>
        <tbody id="visits-table"></tbody>
    </table>
    
    <div id="pagination"></div>
</div>
