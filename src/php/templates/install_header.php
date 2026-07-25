<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalador Grid Bot - HestiaCP</title>
    <style>
        :root {
            --primary: #3498db;
            --success: #2ecc71;
            --warning: #f39c12;
            --error: #e74c3c;
            --dark: #2c3e50;
            --light: #ecf0f1;
        }
        
        * { box-sizing: border-box; margin: 0; padding: 0; }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 40px 20px;
            color: #333;
        }
        
        .container {
            max-width: 900px;
            margin: 0 auto;
        }
        
        .header {
            text-align: center;
            color: white;
            margin-bottom: 40px;
        }
        
        .header h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }
        
        .header p {
            font-size: 1.1rem;
            opacity: 0.9;
        }
        
        .card {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            margin-bottom: 20px;
        }
        
        h2 {
            color: var(--dark);
            margin-bottom: 20px;
            border-bottom: 3px solid var(--primary);
            padding-bottom: 10px;
            display: inline-block;
        }
        
        h3 {
            color: var(--dark);
            margin: 20px 0 10px 0;
            font-size: 1.2rem;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        
        .table th, .table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--light);
        }
        
        .table th {
            background: var(--light);
            font-weight: 600;
            color: var(--dark);
        }
        
        .table tr:hover {
            background: #f8f9fa;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .badge.success { background: #d4edda; color: #155724; }
        .badge.error { background: #f8d7da; color: #721c24; }
        .badge.warning { background: #fff3cd; color: #856404; }
        .badge.info { background: #d1ecf1; color: #0c5460; }
        
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin: 20px 0;
            border-left: 4px solid;
        }
        
        .alert.success {
            background: #d4edda;
            border-color: var(--success);
            color: #155724;
        }
        
        .alert.error {
            background: #f8d7da;
            border-color: var(--error);
            color: #721c24;
        }
        
        .alert.warning {
            background: #fff3cd;
            border-color: var(--warning);
            color: #856404;
        }
        
        .alert.info {
            background: #d1ecf1;
            border-color: var(--primary);
            color: #0c5460;
        }
        
        .btn {
            display: inline-block;
            padding: 12px 30px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            font-size: 1rem;
            margin: 5px;
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        
        .btn-primary:hover {
            background: #2980b9;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(52, 152, 219, 0.4);
        }
        
        .btn-secondary {
            background: #95a5a6;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #7f8c8d;
        }
        
        .btn-danger {
            background: var(--error);
            color: white;
        }
        
        .btn-danger:hover {
            background: #c0392b;
        }
        
        .log-output {
            background: #2c3e50;
            color: #ecf0f1;
            padding: 20px;
            border-radius: 8px;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
            max-height: 400px;
            overflow-y: auto;
            margin: 20px 0;
        }
        
        .log-output div {
            margin: 5px 0;
            line-height: 1.6;
        }
        
        .progress-bar {
            width: 100%;
            height: 8px;
            background: var(--light);
            border-radius: 4px;
            overflow: hidden;
            margin: 20px 0;
        }
        
        .progress {
            height: 100%;
            background: linear-gradient(90deg, var(--success), #27ae60);
            transition: width 0.5s ease;
        }
        
        .text-success { color: var(--success); font-weight: 600; }
        .text-error { color: var(--error); font-weight: 600; }
        .text-warning { color: var(--warning); font-weight: 600; }
        
        code {
            background: #f4f4f4;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            color: #e74c3c;
        }
        
        small {
            color: #7f8c8d;
            display: block;
            margin-top: 5px;
        }
        
        hr {
            border: none;
            border-top: 2px solid var(--light);
            margin: 30px 0;
        }
        
        @media (max-width: 768px) {
            body { padding: 20px 10px; }
            .header h1 { font-size: 2rem; }
            .card { padding: 20px; }
            .table { font-size: 0.9rem; }
            .table th, .table td { padding: 8px 10px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🚀 Grid Bot Installer</h1>
            <p>Instalación optimizada para HestiaCP</p>
        </div>
