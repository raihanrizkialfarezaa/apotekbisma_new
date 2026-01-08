@extends('layouts.master')

@section('title')
    Kartu Stok - {{ $nama_barang }}
@endsection

@push('css')
<link rel="stylesheet" href="{{ asset('/AdminLTE-2/bower_components/bootstrap-datepicker/dist/css/bootstrap-datepicker.min.css') }}">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
    /* ============================================
       ENHANCED UI - KARTU STOK DETAIL
       Features: Freeze Header, Robust Search, 
       Filters, Sorting, Pagination Limit
       Inspired by BPS (Badan Pusat Statistik)
    ============================================ */

    :root {
        --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        --success-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        --info-gradient: linear-gradient(135deg, #2193b0 0%, #6dd5ed 100%);
        --warning-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        --glass-bg: rgba(255, 255, 255, 0.95);
        --glass-border: rgba(255, 255, 255, 0.2);
        --shadow-soft: 0 8px 32px rgba(31, 38, 135, 0.15);
        --shadow-hover: 0 12px 40px rgba(31, 38, 135, 0.25);
    }

    .info-box-content {
        padding: 5px 10px;
        margin-left: 90px;
    }
    .info-box {
        display: block;
        min-height: 90px;
        background: #fff;
        width: 100%;
        box-shadow: 0 1px 1px rgba(0,0,0,0.1);
        border-radius: 2px;
        margin-bottom: 15px;
    }
    .filter-card {
        background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
        border: 1px solid #cbd5e1;
        border-radius: 16px;
        padding: 0;
        margin-bottom: 20px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        overflow: hidden;
    }
    
    .filter-card-header {
        background: linear-gradient(135deg, #1e3a5f 0%, #2563eb 100%);
        color: white;
        padding: 18px 25px;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .filter-card-header h4 {
        margin: 0;
        font-size: 18px;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .filter-card-header h4 i {
        font-size: 20px;
        opacity: 0.9;
    }
    
    .filter-card-header .filter-badge {
        background: rgba(255,255,255,0.2);
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
    }
    
    .filter-card-body {
        padding: 25px;
    }
    
    .filter-section {
        margin-bottom: 20px;
    }
    
    .filter-section:last-child {
        margin-bottom: 0;
    }
    
    .filter-section-label {
        display: flex;
        align-items: center;
        gap: 8px;
        font-weight: 600;
        color: #334155;
        margin-bottom: 12px;
        font-size: 14px;
    }
    
    .filter-section-label i {
        color: #2563eb;
        font-size: 16px;
    }
    
    .quick-filters {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
    }
    
    .quick-filter-btn {
        padding: 10px 20px;
        border: 2px solid #e2e8f0;
        background: #fff;
        border-radius: 25px;
        font-size: 14px;
        font-weight: 600;
        color: #64748b;
        cursor: pointer;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .quick-filter-btn:hover {
        border-color: #2563eb;
        color: #2563eb;
        background: #eff6ff;
        transform: translateY(-2px);
    }
    
    .quick-filter-btn.active {
        background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
        border-color: #2563eb;
        color: white;
        box-shadow: 0 4px 15px rgba(37, 99, 235, 0.4);
    }
    
    .quick-filter-btn i {
        font-size: 14px;
    }
    
    .custom-date-filter {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
    }
    
    .date-input-group {
        display: flex;
        align-items: center;
        gap: 10px;
        flex: 1;
        min-width: 300px;
    }
    
    .date-input-wrapper {
        position: relative;
        flex: 1;
    }
    
    .date-input-wrapper input {
        width: 100%;
        padding: 12px 15px 12px 45px;
        border: 2px solid #e2e8f0;
        border-radius: 10px;
        font-size: 14px;
        transition: all 0.3s ease;
        background: #fff;
    }
    
    .date-input-wrapper input:focus {
        outline: none;
        border-color: #2563eb;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
    }
    
    .date-input-wrapper i {
        position: absolute;
        left: 15px;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
        font-size: 16px;
    }
    
    .date-separator {
        color: #94a3b8;
        font-weight: 600;
        font-size: 14px;
        padding: 0 5px;
    }
    
    .apply-filter-btn {
        padding: 12px 25px;
        background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        border: none;
        border-radius: 10px;
        color: white;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        gap: 8px;
        box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
    }
    
    .apply-filter-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
    }
    
    .filter-divider {
        height: 1px;
        background: linear-gradient(90deg, transparent, #cbd5e1, transparent);
        margin: 20px 0;
    }
    
    .filter-card { position: relative; z-index: 1200; }
    .filter-card .btn, .filter-card button { position: relative; z-index: 1210; }
    .chart-container {
        position: relative;
        height: 300px;
        margin-bottom: 20px;
    }
    .summary-card {
        background: var(--primary-gradient);
        color: white;
        border-radius: 16px;
        padding: 24px;
        margin-bottom: 20px;
        text-align: center;
        box-shadow: var(--shadow-soft);
    }
    .summary-item {
        background: rgba(255,255,255,0.2);
        backdrop-filter: blur(5px);
        border-radius: 8px;
        padding: 12px;
        margin: 8px 0;
        transition: all 0.3s ease;
    }
    .summary-item:hover {
        background: rgba(255,255,255,0.3);
        transform: translateY(-2px);
    }

    /* ============================================
       ENHANCED TABLE TOOLS SECTION
    ============================================ */
    .table-tools-section {
        background: var(--glass-bg);
        backdrop-filter: blur(10px);
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 15px;
        box-shadow: var(--shadow-soft);
        border: 1px solid var(--glass-border);
    }

    .tools-row {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 15px;
    }

    .tool-group {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .tool-label {
        font-weight: 600;
        color: #495057;
        font-size: 13px;
        white-space: nowrap;
    }

    /* Enhanced Search Box */
    .search-container {
        position: relative;
        flex: 1;
        min-width: 280px;
        max-width: 450px;
    }

    .enhanced-search {
        width: 100%;
        padding: 12px 45px 12px 45px;
        border: 2px solid #e0e6ed;
        border-radius: 50px;
        font-size: 14px;
        transition: all 0.3s ease;
        background: #fff;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    }

    .enhanced-search:focus {
        border-color: #667eea;
        box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.15), 0 4px 15px rgba(102, 126, 234, 0.2);
        outline: none;
    }

    .search-icon {
        position: absolute;
        left: 16px;
        top: 50%;
        transform: translateY(-50%);
        color: #9ca3af;
        font-size: 16px;
    }

    .search-clear {
        position: absolute;
        right: 16px;
        top: 50%;
        transform: translateY(-50%);
        background: #ef4444;
        color: white;
        border: none;
        border-radius: 50%;
        width: 22px;
        height: 22px;
        font-size: 12px;
        cursor: pointer;
        display: none;
        align-items: center;
        justify-content: center;
        transition: all 0.2s ease;
    }

    .search-clear:hover {
        background: #dc2626;
        transform: translateY(-50%) scale(1.1);
    }

    .search-clear.visible {
        display: flex;
    }

    /* Pagination Limit Selector */
    .limit-selector {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .limit-select {
        padding: 8px 35px 8px 15px;
        border: 2px solid #e0e6ed;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 500;
        background: #fff url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e") right 10px center no-repeat;
        background-size: 16px;
        cursor: pointer;
        transition: all 0.3s ease;
        appearance: none;
        min-width: 100px;
    }

    .limit-select:focus {
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.15);
        outline: none;
    }

    .limit-select:hover {
        border-color: #667eea;
    }

    /* Column Visibility Toggle */
    .column-toggle-dropdown {
        position: relative;
    }

    .column-toggle-btn {
        padding: 10px 16px;
        border: 2px solid #e0e6ed;
        border-radius: 8px;
        background: #fff;
        cursor: pointer;
        font-size: 14px;
        font-weight: 500;
        color: #495057;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .column-toggle-btn:hover {
        border-color: #667eea;
        color: #667eea;
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.15);
    }

    .column-menu {
        position: absolute;
        top: 100%;
        right: 0;
        background: #fff;
        border: 1px solid #e0e6ed;
        border-radius: 12px;
        box-shadow: 0 10px 40px rgba(0,0,0,0.15);
        padding: 12px;
        min-width: 220px;
        z-index: 1500;
        display: none;
        margin-top: 5px;
    }

    .column-menu.show {
        display: block;
        animation: fadeInDown 0.2s ease;
    }

    @keyframes fadeInDown {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .column-menu-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 12px;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.2s ease;
        font-size: 13px;
    }

    .column-menu-item:hover {
        background: #f3f4f6;
    }

    .column-menu-item input[type="checkbox"] {
        width: 16px;
        height: 16px;
        accent-color: #667eea;
    }

    /* Stats Summary Bar - Enhanced */
    .stats-bar {
        display: flex;
        flex-wrap: wrap;
        gap: 15px;
        padding: 20px;
        background: linear-gradient(135deg, #f8fafc 0%, #eef2f7 100%);
        border-radius: 12px;
        margin: 0 20px 15px 20px;
        box-shadow: inset 0 2px 4px rgba(0,0,0,0.06);
    }

    .stat-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 20px;
        background: #fff;
        border-radius: 10px;
        box-shadow: 0 3px 10px rgba(0,0,0,0.08);
        transition: all 0.3s ease;
        flex: 1;
        min-width: 150px;
    }

    .stat-item:hover {
        transform: translateY(-3px);
        box-shadow: 0 6px 20px rgba(0,0,0,0.12);
    }

    .stat-icon {
        width: 44px;
        height: 44px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        color: #fff;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }

    .stat-icon.total { 
        background: var(--primary-gradient);
        animation: pulseGlow 2s ease-in-out infinite;
    }
    .stat-icon.masuk { 
        background: var(--success-gradient);
    }
    .stat-icon.keluar { 
        background: var(--warning-gradient);
    }
    .stat-icon.filtered { 
        background: var(--info-gradient);
    }

    @keyframes pulseGlow {
        0%, 100% { box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3); }
        50% { box-shadow: 0 4px 20px rgba(102, 126, 234, 0.6); }
    }

    .stat-content {
        display: flex;
        flex-direction: column;
        gap: 2px;
    }

    .stat-value {
        font-size: 22px;
        font-weight: 800;
        color: #1e293b;
        line-height: 1.2;
        letter-spacing: -0.5px;
    }

    .stat-label {
        font-size: 11px;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.6px;
        font-weight: 600;
    }

    /* ============================================
       FREEZE HEADER TABLE STYLING (BPS Style)
       Enhanced with smooth animations & better UX
    ============================================ */
    .table-wrapper {
        position: relative;
        max-height: 650px; /* Increased height for better visibility */
        overflow: auto;
        border-radius: 12px;
        box-shadow: var(--shadow-soft);
        background: #fff;
        /* Custom scrollbar styling */
    }

    .table-wrapper::-webkit-scrollbar {
        width: 12px;
        height: 12px;
    }

    .table-wrapper::-webkit-scrollbar-track {
        background: #f1f5f9;
        border-radius: 10px;
    }

    .table-wrapper::-webkit-scrollbar-thumb {
        background: linear-gradient(180deg, #667eea 0%, #764ba2 100%);
        border-radius: 10px;
        border: 2px solid #f1f5f9;
    }

    .table-wrapper::-webkit-scrollbar-thumb:hover {
        background: linear-gradient(180deg, #5a67d8 0%, #6b46c1 100%);
    }

    #kartu-stok-table {
        min-width: 900px;
        border-collapse: separate;
        border-spacing: 0;
        width: 100%;
    }

    /* Freeze Header */
    #kartu-stok-table thead {
        position: sticky;
        top: 0;
        z-index: 100;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    }

    #kartu-stok-table thead th {
        background: linear-gradient(180deg, #667eea 0%, #5a67d8 100%);
        color: #fff;
        font-weight: 700; /* Increased weight for better readability */
        padding: 16px 14px; /* More padding for better UX */
        text-align: center;
        border: none;
        border-bottom: 3px solid #4c51bf;
        white-space: nowrap !important;
        position: relative;
        cursor: pointer;
        transition: all 0.3s ease; /* Smoother transition */
        font-size: 13px;
        text-transform: uppercase;
        letter-spacing: 0.8px; /* Better letter spacing */
        user-select: none; /* Prevent text selection on sorting */
    }

    #kartu-stok-table thead th:hover {
        background: linear-gradient(180deg, #5a67d8 0%, #4c51bf 100%);
        transform: translateY(-2px); /* Subtle lift effect */
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
    }

    #kartu-stok-table thead th:active {
        transform: translateY(0); /* Press effect */
    }

    /* Sorting Icons - Enhanced */
    .sort-icon {
        display: inline-flex;
        flex-direction: column;
        margin-left: 8px;
        vertical-align: middle;
        opacity: 0.4;
        transition: all 0.3s ease;
        font-size: 11px;
    }

    .sort-icon i {
        font-size: 11px;
        line-height: 1.2;
        display: block;
    }

    .sort-icon .fa-caret-up {
        margin-bottom: -2px;
    }

    th:hover .sort-icon {
        opacity: 0.8;
        transform: scale(1.1);
    }

    th.sorted .sort-icon {
        opacity: 1;
    }

    th.sorted-asc .sort-icon .fa-caret-up {
        color: #fbbf24;
        animation: bounce 0.5s ease;
    }

    th.sorted-desc .sort-icon .fa-caret-down {
        color: #fbbf24;
        animation: bounce 0.5s ease;
    }

    @keyframes bounce {
        0%, 100% { transform: translateY(0); }
        50% { transform: translateY(-3px); }
    }

    /* Table Body Styling - Enhanced */
    #kartu-stok-table tbody tr {
        transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        border-left: 3px solid transparent;
    }

    #kartu-stok-table tbody tr:nth-child(even) {
        background: #f9fafb;
    }

    #kartu-stok-table tbody tr:nth-child(odd) {
        background: #ffffff;
    }

    #kartu-stok-table tbody tr:hover {
        background: linear-gradient(90deg, rgba(102, 126, 234, 0.12) 0%, rgba(118, 75, 162, 0.12) 100%) !important;
        transform: translateX(4px);
        border-left-color: #667eea;
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.15);
        cursor: pointer;
    }

    #kartu-stok-table tbody td {
        padding: 14px 12px; /* Better padding */
        border-bottom: 1px solid #e5e7eb;
        font-size: 13px;
        vertical-align: middle;
        transition: all 0.2s ease;
    }

    /* Highlight Search Results - Enhanced */
    .search-highlight {
        background: linear-gradient(120deg, #fef08a 0%, #fde047 100%);
        padding: 3px 6px;
        border-radius: 4px;
        font-weight: 700;
        color: #92400e;
        box-shadow: 0 2px 4px rgba(250, 204, 21, 0.3);
        animation: highlightPulse 1.5s ease-in-out;
    }

    @keyframes highlightPulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.8; transform: scale(1.02); }
    }

    /* Stock Type Badges - Enhanced */
    .badge-masuk {
        background: var(--success-gradient);
        color: #fff;
        padding: 5px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 700;
        box-shadow: 0 3px 8px rgba(16, 185, 129, 0.3);
        display: inline-block;
        animation: fadeInScale 0.3s ease;
    }

    .badge-keluar {
        background: var(--warning-gradient);
        color: #fff;
        padding: 5px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 700;
        box-shadow: 0 3px 8px rgba(245, 87, 108, 0.3);
        display: inline-block;
        animation: fadeInScale 0.3s ease;
    }

    @keyframes fadeInScale {
        from { opacity: 0; transform: scale(0.9); }
        to { opacity: 1; transform: scale(1); }
    }

    /* Column Filter Inputs */
    .column-filter-row th {
        background: #4c51bf !important;
        padding: 8px !important;
    }

    .column-filter {
        width: 100%;
        padding: 8px 12px;
        border: 2px solid transparent;
        border-radius: 6px;
        font-size: 12px;
        background: rgba(255,255,255,0.95);
        transition: all 0.3s ease;
    }

    .column-filter:focus {
        outline: none;
        border-color: #fbbf24;
        box-shadow: 0 0 0 3px rgba(251, 191, 36, 0.3);
        background: #fff;
    }

    .column-filter::placeholder {
        color: #9ca3af;
        font-style: italic;
        font-size: 11px;
    }

    /* Filter Active State - Shows when filter has value */
    .column-filter.filter-active {
        border-color: #10b981;
        background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
        font-weight: 600;
        color: #047857;
    }

    .column-filter.filter-active::placeholder {
        color: #059669;
    }

    /* Clear filter button for each column */
    .filter-cell {
        position: relative;
    }

    .filter-clear-btn {
        position: absolute;
        right: 8px;
        top: 50%;
        transform: translateY(-50%);
        background: #ef4444;
        color: white;
        border: none;
        border-radius: 50%;
        width: 18px;
        height: 18px;
        font-size: 10px;
        cursor: pointer;
        display: none;
        align-items: center;
        justify-content: center;
        transition: all 0.2s ease;
    }

    .filter-clear-btn:hover {
        background: #dc2626;
        transform: translateY(-50%) scale(1.1);
    }

    .column-filter.filter-active + .filter-clear-btn {
        display: flex;
    }

    /* Enhanced Pagination - Modern Design */
    .enhanced-pagination {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 20px;
        padding: 22px 25px;
        background: linear-gradient(135deg, #f9fafb 0%, #f3f4f6 100%);
        border-radius: 0 0 12px 12px;
        border-top: 2px solid #e5e7eb;
        box-shadow: 0 -2px 10px rgba(0,0,0,0.03);
    }

    .pagination-info {
        font-size: 14px;
        color: #6b7280;
        font-weight: 500;
        padding: 8px 16px;
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 2px 6px rgba(0,0,0,0.05);
    }

    .pagination-info strong {
        color: #1f2937;
        font-weight: 700;
    }

    .pagination-controls {
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .page-btn {
        padding: 10px 16px;
        border: 2px solid #e5e7eb;
        background: #fff;
        border-radius: 10px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        color: #475569;
        min-width: 42px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .page-btn:hover:not(:disabled) {
        border-color: #667eea;
        color: #667eea;
        background: #eff6ff;
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(102, 126, 234, 0.25);
    }

    .page-btn.active {
        background: var(--primary-gradient);
        color: #fff;
        border-color: transparent;
        box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        transform: scale(1.05);
    }

    .page-btn:disabled {
        opacity: 0.4;
        cursor: not-allowed;
        background: #f3f4f6;
    }

    .page-btn:active:not(:disabled) {
        transform: translateY(0) scale(0.95);
    }

    .page-jump {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-left: 15px;
        padding: 8px 16px;
        background: #fff;
        border-radius: 10px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    }

    .page-jump-input {
        width: 65px;
        padding: 10px 12px;
        border: 2px solid #e5e7eb;
        border-radius: 8px;
        text-align: center;
        font-size: 14px;
        font-weight: 600;
        color: #1f2937;
        transition: all 0.3s ease;
    }

    .page-jump-input:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.15);
    }
    }

    .page-jump-input:focus {
        border-color: #667eea;
        outline: none;
    }

    /* Quick Action Buttons - Enhanced */
    .quick-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }

    .action-btn {
        padding: 11px 22px;
        border: none;
        border-radius: 10px;
        font-size: 13px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        display: flex;
        align-items: center;
        gap: 8px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        position: relative;
        overflow: hidden;
    }

    .action-btn::before {
        content: '';
        position: absolute;
        top: 50%;
        left: 50%;
        width: 0;
        height: 0;
        border-radius: 50%;
        background: rgba(255,255,255,0.3);
        transform: translate(-50%, -50%);
        transition: width 0.6s, height 0.6s;
    }

    .action-btn:hover::before {
        width: 300px;
        height: 300px;
    }

    .action-btn i,
    .action-btn span {
        position: relative;
        z-index: 1;
    }

    .action-btn.primary {
        background: var(--primary-gradient);
        color: #fff;
        box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
    }

    .action-btn.success {
        background: var(--success-gradient);
        color: #fff;
        box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
    }

    .action-btn.info {
        background: var(--info-gradient);
        color: #fff;
        box-shadow: 0 4px 15px rgba(33, 147, 176, 0.3);
    }

    .action-btn.warning {
        background: var(--warning-gradient);
        color: #fff;
        box-shadow: 0 4px 15px rgba(240, 147, 251, 0.3);
    }

    .action-btn:hover {
        transform: translateY(-3px) scale(1.02);
        box-shadow: 0 8px 25px rgba(0,0,0,0.25);
    }

    .action-btn:active {
        transform: translateY(-1px) scale(0.98);
    }

    /* Loading Overlay - Enhanced */
    .table-loading {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(255,255,255,0.95);
        backdrop-filter: blur(8px);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 200;
        border-radius: 12px;
        animation: fadeIn 0.3s ease;
    }

    .loading-spinner {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 18px;
        animation: slideUp 0.5s ease;
    }

    .loading-spinner i {
        font-size: 48px;
        color: #667eea;
        animation: spin 1.2s linear infinite;
        filter: drop-shadow(0 4px 8px rgba(102, 126, 234, 0.3));
    }

    @keyframes spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }

    .loading-text {
        font-size: 15px;
        color: #475569;
        font-weight: 600;
        letter-spacing: 0.5px;
    }

    /* No Data State - Enhanced */
    .no-data {
        text-align: center;
        padding: 80px 20px;
        color: #94a3b8;
        animation: fadeIn 0.6s ease;
    }

    .no-data i {
        font-size: 72px;
        margin-bottom: 24px;
        opacity: 0.4;
        animation: float 3s ease-in-out infinite;
    }

    @keyframes float {
        0%, 100% { transform: translateY(0); }
        50% { transform: translateY(-10px); }
    }

    .no-data h4 {
        color: #64748b;
        margin-bottom: 12px;
        font-size: 20px;
        font-weight: 700;
    }

    .no-data p {
        color: #94a3b8;
        font-size: 14px;
    }

    /* Global Animations */
    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }

    @keyframes slideUp {
        from { 
            opacity: 0;
            transform: translateY(20px);
        }
        to { 
            opacity: 1;
            transform: translateY(0);
        }
    }

    @keyframes fadeInDown {
        from { 
            opacity: 0; 
            transform: translateY(-10px); 
        }
        to { 
            opacity: 1; 
            transform: translateY(0); 
        }
    }

    /* Mobile Responsive - Enhanced */
    @media (max-width: 768px) {
        .info-box {
            margin-bottom: 10px;
        }
        
        .info-box-content {
            margin-left: 70px;
            padding: 5px;
        }
        
        .info-box-icon {
            width: 70px;
            height: 70px;
            line-height: 70px;
        }
        
        .filter-card {
            padding: 15px;
        }
        
        .filter-card-header {
            padding: 15px 18px;
            flex-direction: column;
            gap: 10px;
            align-items: flex-start;
        }
        
        .quick-filters {
            flex-direction: column;
        }
        
        .quick-filter-btn {
            width: 100%;
            justify-content: center;
        }
        
        .date-input-group {
            flex-direction: column;
            min-width: 100%;
        }
        
        .stats-bar {
            flex-direction: column;
            padding: 15px;
        }
        
        .stat-item {
            width: 100%;
        }
        
        .table-wrapper {
            max-height: 450px;
        }
        
        .tools-row {
            flex-direction: column;
        }
        
        .search-container {
            max-width: 100%;
            min-width: 100%;
        }
        
        .quick-actions {
            width: 100%;
        }
        
        .action-btn {
            flex: 1;
            justify-content: center;
        }
        
        .enhanced-pagination {
            flex-direction: column;
            gap: 15px;
        }
        
        .pagination-controls {
            flex-wrap: wrap;
            justify-content: center;
        }
        
        .page-jump {
            margin-left: 0;
        }
        
        .btn-group .btn {
            font-size: 11px;
            padding: 6px 10px;
            margin: 3px;
        }
        
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        .box-tools .btn {
            margin: 2px;
            font-size: 11px;
            padding: 4px 8px;
        }
        
        .form-group label {
            font-size: 12px;
        }
        
        .input-group .form-control {
            font-size: 12px;
        }

        .tools-row {
            flex-direction: column;
            align-items: stretch;
        }

        .search-container {
            max-width: 100%;
        }

        .stats-bar {
            flex-direction: column;
        }

        .enhanced-pagination {
            flex-direction: column;
            text-align: center;
        }

        .table-wrapper {
            max-height: 450px;
        }
    }

    @media (min-width: 769px) and (max-width: 1366px) and (orientation: landscape) {
        .box-body.table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .table thead th, .table tbody td {
            font-size: 13px;
            padding: 8px 6px;
            white-space: normal;
            word-break: break-word;
        }

        .table td.text-center, .table th.text-center {
            text-align: center;
        }

        .table-responsive { 
            display: block;
            width: 100%;
            overflow-x: auto;
        }

        .info-box-content { margin-left: 80px; }
    }

    @media (min-width: 600px) and (max-width: 1024px) {
        .box-body.table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .table thead th, .table tbody td {
            font-size: 13px;
            padding: 7px 5px;
            white-space: normal;
            word-break: break-word;
        }

        .table-responsive { 
            display: block;
            width: 100%;
            overflow-x: auto;
        }
    }

    @media (min-width: 1025px) and (max-width: 1440px) and (orientation: landscape) {
        .table thead th, .table tbody td {
            font-size: 13px;
            padding: 8px 6px;
        }
    }

    @media (min-width: 600px) and (max-width: 820px) and (orientation: landscape) {
        .filter-card { padding: 12px; }
        .btn-group .btn { font-size: 12px; padding: 6px 10px; }
        .box-body.table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .info-box-content { margin-left: 70px; }
    }

    @media (min-width: 821px) and (max-width: 1024px) and (orientation: landscape) {
        .filter-card { padding: 15px; }
        .btn-group .btn { font-size: 13px; padding: 7px 12px; }
        .box-body.table-responsive { overflow-x: auto; }
    }

    @media (min-width: 600px) and (max-width: 1024px) and (orientation: portrait) {
        .btn-group .btn { display: inline-block; margin: 3px 2px; }
        .table-responsive { overflow-x: auto; }
        .filter-card { z-index: 1200; }
    }

    @media (min-width: 600px) and (max-width: 1440px) {
        .filter-card .btn, .box-tools .btn { min-height: 40px; line-height: 20px; }
    }

    /* Print Styles */
    @media print {
        .table-tools-section,
        .filter-card,
        .stats-bar,
        .enhanced-pagination,
        .box-tools {
            display: none !important;
        }

        .table-wrapper {
            max-height: none;
            overflow: visible;
        }

        #kartu-stok-table thead {
            position: relative;
        }
    }
</style>
@endpush

@section('breadcrumb')
    @parent
    <li><a href="{{ route('kartu_stok.index') }}">Kartu Stok</a></li>
    <li class="active">{{ $nama_barang }}</li>
@endsection

@section('content')
<!-- Product Information Row -->
<div class="row">
    <div class="col-lg-3 col-xs-6">
        <div class="info-box">
            <span class="info-box-icon bg-aqua"><i class="fa fa-barcode"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Kode Produk</span>
                <span class="info-box-number">{{ $produk->kode_produk }}</span>
            </div>
        </div>
    </div>

    <div class="col-lg-3 col-xs-6">
        <div class="info-box">
            <span class="info-box-icon bg-green"><i class="fa fa-cubes"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Stok Saat Ini</span>
                <span class="info-box-number">{{ format_uang($produk->stok) }}</span>
            </div>
        </div>
    </div>

    <div class="col-lg-3 col-xs-6">
        <div class="info-box">
            <span class="info-box-icon bg-yellow"><i class="fa fa-tags"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Kategori</span>
                <span class="info-box-number">{{ $produk->kategori->nama_kategori ?? 'N/A' }}</span>
            </div>
        </div>
    </div>

    <div class="col-lg-3 col-xs-6">
        <div class="info-box">
            <span class="info-box-icon bg-red"><i class="fa fa-money"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Harga Beli</span>
                <span class="info-box-number">{{ format_uang($produk->harga_beli) }}</span>
            </div>
        </div>
    </div>
</div>

<!-- Summary & Chart Row -->
<div class="row">
    <div class="col-md-8">
        <div class="box box-primary">
            <div class="box-header with-border">
                <h3 class="box-title">
                    <i class="fa fa-line-chart"></i> Grafik Pergerakan Stok (30 Hari Terakhir)
                </h3>
            </div>
            <div class="box-body">
                <div class="chart-container">
                    <canvas id="stockChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <div class="summary-card">
            <h4><i class="fa fa-chart-pie"></i> Ringkasan Periode</h4>
            
            <div class="summary-item">
                <strong>Minggu Ini</strong><br>
                <small>Masuk: {{ $stok_data['summary']['periode_minggu']['masuk'] }} | Keluar: {{ $stok_data['summary']['periode_minggu']['keluar'] }}</small>
            </div>
            
            <div class="summary-item">
                <strong>Bulan Ini</strong><br>
                <small>Masuk: {{ $stok_data['summary']['periode_bulan']['masuk'] }} | Keluar: {{ $stok_data['summary']['periode_bulan']['keluar'] }}</small>
            </div>
            
            <div class="summary-item">
                <strong>Tahun Ini</strong><br>
                <small>Masuk: {{ $stok_data['summary']['periode_tahun']['masuk'] }} | Keluar: {{ $stok_data['summary']['periode_tahun']['keluar'] }}</small>
            </div>
            
            <div class="summary-item">
                <strong>Total Keseluruhan</strong><br>
                <small>{{ $stok_data['summary']['total_transaksi'] }} transaksi</small>
            </div>
        </div>
    </div>
</div>

<!-- Filter Section - Enhanced UI -->
<div class="row">
    <div class="col-lg-12">
        <div class="filter-card">
            <div class="filter-card-header">
                <h4><i class="fa fa-filter"></i> Filter Data</h4>
                <span class="filter-badge" id="active-filter-badge">Semua Data</span>
            </div>
            <div class="filter-card-body">
                <!-- Quick Filters -->
                <div class="filter-section">
                    <div class="filter-section-label">
                        <i class="fa fa-bolt"></i>
                        <span>Filter Cepat</span>
                    </div>
                    <div class="quick-filters">
                        <button type="button" class="quick-filter-btn active filter-btn" data-filter="all">
                            <i class="fa fa-globe"></i> Semua
                        </button>
                        <button type="button" class="quick-filter-btn filter-btn" data-filter="today">
                            <i class="fa fa-calendar-check-o"></i> Hari Ini
                        </button>
                        <button type="button" class="quick-filter-btn filter-btn" data-filter="week">
                            <i class="fa fa-calendar-minus-o"></i> Minggu Ini
                        </button>
                        <button type="button" class="quick-filter-btn filter-btn" data-filter="month">
                            <i class="fa fa-calendar"></i> Bulan Ini
                        </button>
                        <button type="button" class="quick-filter-btn filter-btn" data-filter="year">
                            <i class="fa fa-calendar-o"></i> Tahun Ini
                        </button>
                    </div>
                </div>
                
                <div class="filter-divider"></div>
                
                <!-- Custom Date Filter -->
                <div class="filter-section">
                    <div class="filter-section-label">
                        <i class="fa fa-calendar-plus-o"></i>
                        <span>Filter Kustom (Rentang Tanggal)</span>
                    </div>
                    <div class="custom-date-filter">
                        <div class="date-input-group">
                            <div class="date-input-wrapper">
                                <i class="fa fa-calendar"></i>
                                <input type="date" id="start_date" placeholder="Tanggal Mulai">
                            </div>
                            <span class="date-separator">sampai</span>
                            <div class="date-input-wrapper">
                                <i class="fa fa-calendar"></i>
                                <input type="date" id="end_date" placeholder="Tanggal Akhir">
                            </div>
                        </div>
                        <button type="button" class="apply-filter-btn" id="apply-custom-filter">
                            <i class="fa fa-search"></i> Terapkan Filter
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Stock Movement Table - Enhanced UI -->
<div class="row">
    <div class="col-lg-12">
        <div class="box box-primary" style="border-radius: 12px; overflow: hidden; box-shadow: 0 8px 32px rgba(31, 38, 135, 0.15);">
            <div class="box-header with-border" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px;">
                <h3 class="box-title" style="color: white; font-weight: 600;">
                    <i class="fa fa-history"></i> Riwayat Pergerakan Stok - {{ $nama_barang }}
                </h3>
                <div class="box-tools pull-right">
                    <a href="{{ route('kartu_stok.export_pdf', $produk_id) }}" target="_blank" class="btn btn-info btn-sm btn-flat">
                        <i class="fa fa-file-pdf-o"></i> Export PDF
                    </a>
                    <a href="{{ route('kartu_stok.index') }}" class="btn btn-default btn-sm btn-flat">
                        <i class="fa fa-arrow-left"></i> Kembali
                    </a>
                </div>
            </div>
            
            <!-- Enhanced Table Tools Section -->
            <div class="table-tools-section">
                <div class="tools-row">
                    <!-- Enhanced Search Box -->
                    <div class="search-container">
                        <i class="fa fa-search search-icon"></i>
                        <input type="text" id="enhanced-search" class="enhanced-search" placeholder="Cari data (tanggal, supplier, keterangan...)">
                        <button type="button" id="clear-search" class="search-clear">
                            <i class="fa fa-times"></i>
                        </button>
                    </div>
                    
                    <!-- Pagination Limit Selector -->
                    <div class="tool-group">
                        <span class="tool-label"><i class="fa fa-list-ol"></i> Tampilkan:</span>
                        <select id="page-limit" class="limit-select">
                            <option value="10">10 Data</option>
                            <option value="25" selected>25 Data</option>
                            <option value="50">50 Data</option>
                            <option value="100">100 Data</option>
                            <option value="-1">Semua</option>
                        </select>
                    </div>
                    
                    <!-- Column Visibility Toggle -->
                    <div class="column-toggle-dropdown">
                        <button type="button" class="column-toggle-btn" id="column-toggle-btn">
                            <i class="fa fa-columns"></i> Kolom
                            <i class="fa fa-caret-down"></i>
                        </button>
                        <div class="column-menu" id="column-menu">
                            <div class="column-menu-item">
                                <input type="checkbox" id="col-no" checked data-column="0">
                                <label for="col-no">No</label>
                            </div>
                            <div class="column-menu-item">
                                <input type="checkbox" id="col-tanggal" checked data-column="1">
                                <label for="col-tanggal">Tanggal</label>
                            </div>
                            <div class="column-menu-item">
                                <input type="checkbox" id="col-masuk" checked data-column="2">
                                <label for="col-masuk">Stok Masuk</label>
                            </div>
                            <div class="column-menu-item">
                                <input type="checkbox" id="col-keluar" checked data-column="3">
                                <label for="col-keluar">Stok Keluar</label>
                            </div>
                            <div class="column-menu-item">
                                <input type="checkbox" id="col-akhir" checked data-column="4">
                                <label for="col-akhir">Stok Akhir</label>
                            </div>
                            <div class="column-menu-item">
                                <input type="checkbox" id="col-expired" checked data-column="5">
                                <label for="col-expired">Expired Date</label>
                            </div>
                            <div class="column-menu-item">
                                <input type="checkbox" id="col-batch" checked data-column="6">
                                <label for="col-batch">Nomor Batch</label>
                            </div>
                            <div class="column-menu-item">
                                <input type="checkbox" id="col-supplier" checked data-column="7">
                                <label for="col-supplier">Supplier</label>
                            </div>
                            <div class="column-menu-item">
                                <input type="checkbox" id="col-keterangan" checked data-column="8">
                                <label for="col-keterangan">Keterangan</label>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Quick Actions -->
                    <div class="quick-actions">
                        <button type="button" class="action-btn info" id="reset-filters" title="Reset semua filter">
                            <i class="fa fa-refresh"></i> Reset Filter
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Stats Summary Bar -->
            <div class="stats-bar">
                <div class="stat-item">
                    <div class="stat-icon total">
                        <i class="fa fa-database"></i>
                    </div>
                    <div class="stat-content">
                        <span class="stat-value" id="stat-total">-</span>
                        <span class="stat-label">Total Data</span>
                    </div>
                </div>
                <div class="stat-item">
                    <div class="stat-icon masuk">
                        <i class="fa fa-arrow-down"></i>
                    </div>
                    <div class="stat-content">
                        <span class="stat-value" id="stat-masuk">-</span>
                        <span class="stat-label">Total Masuk</span>
                    </div>
                </div>
                <div class="stat-item">
                    <div class="stat-icon keluar">
                        <i class="fa fa-arrow-up"></i>
                    </div>
                    <div class="stat-content">
                        <span class="stat-value" id="stat-keluar">-</span>
                        <span class="stat-label">Total Keluar</span>
                    </div>
                </div>
                <div class="stat-item">
                    <div class="stat-icon filtered">
                        <i class="fa fa-filter"></i>
                    </div>
                    <div class="stat-content">
                        <span class="stat-value" id="stat-filtered">-</span>
                        <span class="stat-label">Data Terfilter</span>
                    </div>
                </div>
            </div>
            
            <!-- Table Container with Freeze Header -->
            <div class="table-wrapper" id="table-wrapper">
                <!-- Loading Overlay -->
                <div class="table-loading" id="table-loading" style="display: none;">
                    <div class="loading-spinner">
                        <i class="fa fa-spinner"></i>
                        <span class="loading-text">Memuat data...</span>
                    </div>
                </div>
                
                <table class="table table-striped table-bordered table-hover" id="kartu-stok-table">
                    <thead>
                        <!-- Main Header Row -->
                        <tr>
                            <th width="5%" class="text-center" data-column="0" data-sort="none">
                                No
                            </th>
                            <th class="text-center" data-column="1" data-sort="desc" style="min-width: 150px;">
                                Tanggal & Waktu
                                <span class="sort-icon">
                                    <i class="fa fa-caret-up"></i>
                                    <i class="fa fa-caret-down"></i>
                                </span>
                            </th>
                            <th class="text-center" data-column="2" data-sort="none">
                                Stok Masuk
                                <span class="sort-icon">
                                    <i class="fa fa-caret-up"></i>
                                    <i class="fa fa-caret-down"></i>
                                </span>
                            </th>
                            <th class="text-center" data-column="3" data-sort="none">
                                Stok Keluar
                                <span class="sort-icon">
                                    <i class="fa fa-caret-up"></i>
                                    <i class="fa fa-caret-down"></i>
                                </span>
                            </th>
                            <th class="text-center" data-column="4" data-sort="none">
                                Stok Akhir
                                <span class="sort-icon">
                                    <i class="fa fa-caret-up"></i>
                                    <i class="fa fa-caret-down"></i>
                                </span>
                            </th>
                            <th class="text-center" data-column="5" data-sort="none">
                                Expired Date
                                <span class="sort-icon">
                                    <i class="fa fa-caret-up"></i>
                                    <i class="fa fa-caret-down"></i>
                                </span>
                            </th>
                            <th class="text-center" data-column="6" data-sort="none">
                                Nomor Batch
                            </th>
                            <th class="text-center" data-column="7" data-sort="none">
                                Supplier
                                <span class="sort-icon">
                                    <i class="fa fa-caret-up"></i>
                                    <i class="fa fa-caret-down"></i>
                                </span>
                            </th>
                            <th class="text-center" data-column="8" data-sort="none">
                                Keterangan
                            </th>
                        </tr>
                        <!-- Column Filter Row -->
                        <tr class="column-filter-row">
                            <th></th>
                            <th><input type="text" class="column-filter" data-column="1" placeholder="Filter tanggal..."></th>
                            <th><input type="text" class="column-filter" data-column="2" placeholder="Filter..."></th>
                            <th><input type="text" class="column-filter" data-column="3" placeholder="Filter..."></th>
                            <th><input type="text" class="column-filter" data-column="4" placeholder="Filter..."></th>
                            <th><input type="text" class="column-filter" data-column="5" placeholder="Filter..."></th>
                            <th><input type="text" class="column-filter" data-column="6" placeholder="Filter..."></th>
                            <th><input type="text" class="column-filter" data-column="7" placeholder="Filter..."></th>
                            <th><input type="text" class="column-filter" data-column="8" placeholder="Filter..."></th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Data will be loaded via DataTables -->
                    </tbody>
                </table>
            </div>
            
            <!-- Enhanced Custom Pagination -->
            <div class="enhanced-pagination" id="custom-pagination">
                <div class="pagination-info">
                    Menampilkan <strong id="page-start">0</strong> sampai <strong id="page-end">0</strong> dari <strong id="page-total">0</strong> data
                </div>
                <div class="pagination-controls" id="pagination-controls">
                    <!-- Pagination buttons will be generated by JavaScript -->
                </div>
                <div class="page-jump">
                    <span class="tool-label">Ke halaman:</span>
                    <input type="number" id="page-jump-input" class="page-jump-input" min="1" value="1">
                    <button type="button" class="page-btn" id="page-jump-btn">
                        <i class="fa fa-arrow-right"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('/AdminLTE-2/bower_components/bootstrap-datepicker/dist/js/bootstrap-datepicker.min.js') }}"></script>
<script>
    let table;
    let currentFilter = 'all'; // Default filter - show ALL data including historical
    let searchTerm = ''; // Global search term for highlighting
    // Product-level batch number to display in table column without backend changes
    const produkBatch = @json($produk->batch);

    $(function () {
        // Show loading initially
        $('#table-loading').show();

        // Initialize DataTable with enhanced configuration
        // Initial Load Data Direct from Controller (Robust & Fast)
        const initialData = @json($dataStokLengkap);

        table = $('#kartu-stok-table').DataTable({
            destroy: true, // Ensure fresh init
            responsive: {
                details: {
                    type: 'column',
                    target: 'tr'
                }
            },
            data: initialData, // Use direct data for instant load
            processing: true,
            serverSide: false, // Client-side processing is key
            autoWidth: false,
            scrollX: false,
            scrollCollapse: true,
            // Keep AJAX config for reloads (Reset/Filter)
            ajax: {
                url: '{{ route('kartu_stok.data', $produk_id) }}',
                type: 'GET',
                dataSrc: 'data',
                data: function(d) {
                    d.date_filter = typeof currentFilter !== 'undefined' ? currentFilter : 'all';
                    if (d.date_filter === 'custom') {
                        d.start_date = $('#start_date').val();
                        d.end_date = $('#end_date').val();
                    }
                },
                beforeSend: function() { $('#table-loading').show(); },
                complete: function() { $('#table-loading').hide(); },
                error: function() { $('#table-loading').hide(); }
            },
            stateSave: false, // Disable state saving to fix sorting issues
            order: [[1, 'desc']], // Force desc sorting
            columnDefs: [ {
                "searchable": false,
                "orderable": false,
                "targets": 0
            } ],
            columns: [
                {
                    data: null, 
                    searchable: false, 
                    sortable: false, 
                    className: 'text-center',
                    // Use standard render, but we will overwrite it with event listener
                    render: function (data, type, row, meta) {
                        return meta.settings._iDisplayStart + meta.row + 1;
                    }
                },
                {
                    data: 'waktu_raw',
                    name: 'waktu_raw',
                    className: 'text-center',
                    orderable: true,
                    type: 'string', 
                    render: function(data, type, row) {
                        if (type === 'sort' || type === 'type') {
                            return data;
                        }
                        if (!data) return '-';
                        try {
                            const dt = new Date(data);
                            if (!isNaN(dt.getTime())) {
                                const dateStr = dt.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
                                const timeStr = dt.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
                                return '<div style="line-height: 1.4;"><strong>' + dateStr + '</strong><br><small style="color: #6b7280;">' + timeStr + '</small></div>';
                            }
                        } catch (e) {}
                        return row.tanggal || data;
                    }
                },
                {data: 'stok_masuk', className: 'text-center', orderable: true},
                {data: 'stok_keluar', className: 'text-center', orderable: true},
                {data: 'stok_sisa', className: 'text-center', orderable: true},
                {
                    data: 'expired_date',
                    className: 'text-center',
                    orderable: true,
                    defaultContent: '',
                    render: function(data, type, row) {
                        if (!data || data === '') return '-';
                        if (type === 'display') {
                            try {
                                const dt = new Date(data);
                                if (!isNaN(dt.getTime())) {
                                    return highlightText(dt.toLocaleDateString('id-ID'));
                                }
                            } catch (e) {}
                            return highlightText(data);
                        }
                        return data;
                    }
                },
                {
                    data: 'batch',
                    className: 'text-center',
                    orderable: false,
                    defaultContent: '',
                    render: function(data, type, row) {
                        if (!produkBatch || produkBatch === '') return '-';
                        return highlightText(produkBatch);
                    }
                },
                {
                    data: 'supplier',
                    className: 'text-left',
                    orderable: true,
                    defaultContent: '-',
                    render: function(data, type, row) {
                        if (!data || data === '') return '-';
                        return highlightText(data);
                    }
                },
                {
                    data: 'keterangan',
                    className: 'text-left',
                    orderable: false,
                    render: function(data, type, row) {
                        if (!data || data === '') return '-';
                        return highlightText(data);
                    }
                }
            ],
            // Hide default DataTables controls - we use custom ones
            dom: 't',
            language: {
                processing: '<i class="fa fa-spinner fa-spin"></i> Memuat data...',
                search: '',
                lengthMenu: '',
                info: '',
                infoEmpty: '',
                infoFiltered: '',
                zeroRecords: '<div class="no-data"><i class="fa fa-inbox"></i><h4>Tidak ada data</h4><p>Tidak ada riwayat pergerakan stok untuk periode ini</p></div>',
                emptyTable: '<div class="no-data"><i class="fa fa-inbox"></i><h4>Belum ada data</h4><p>Belum ada riwayat pergerakan stok</p></div>',
                paginate: {
                    first: 'Pertama',
                    last: 'Terakhir',
                    next: 'Selanjutnya',
                    previous: 'Sebelumnya'
                }
            },
            ordering: false, // Disabled - data is pre-sorted by backend
            order: [], // No default order needed
            pageLength: 25,
            drawCallback: function(settings) {
                updateCustomPagination(settings);
                updateStatsSummary(settings);
                highlightSearchResults();
            }
        });
        
        // Force sequential numbering regardless of sorting
        table.on('order.dt search.dt', function () {
            table.column(0, {search:'applied', order:'applied'}).nodes().each( function (cell, i) {
                cell.innerHTML = i + 1;
            });
        }).draw();

        // ============================================
        // ENHANCED SEARCH FUNCTIONALITY
        // ============================================
        let searchTimeout;
        $('#enhanced-search').on('input', function() {
            const value = $(this).val();
            searchTerm = value;
            
            // Show/hide clear button
            if (value.length > 0) {
                $('#clear-search').addClass('visible');
            } else {
                $('#clear-search').removeClass('visible');
            }
            
            // Debounce search
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(function() {
                table.search(value).draw();
            }, 300);
        });

        // Clear search
        $('#clear-search').on('click', function() {
            $('#enhanced-search').val('');
            searchTerm = '';
            $(this).removeClass('visible');
            table.search('').draw();
        });

        // ============================================
        // PAGINATION LIMIT SELECTOR
        // ============================================
        $('#page-limit').on('change', function() {
            const limit = parseInt($(this).val());
            table.page.len(limit).draw();
        });

        // ============================================
        // COLUMN VISIBILITY TOGGLE
        // ============================================
        $('#column-toggle-btn').on('click', function(e) {
            e.stopPropagation();
            $('#column-menu').toggleClass('show');
        });

        // Close menu when clicking outside
        $(document).on('click', function(e) {
            if (!$(e.target).closest('.column-toggle-dropdown').length) {
                $('#column-menu').removeClass('show');
            }
        });

        // Handle column visibility checkbox
        $('#column-menu input[type="checkbox"]').on('change', function() {
            const column = $(this).data('column');
            const isVisible = $(this).is(':checked');
            table.column(column).visible(isVisible);
        });

        // ============================================
        // COLUMN FILTERS - CLIENT SIDE (ROBUST!)
        // ============================================
        // Apply individual column filtering using DataTables search API
        $('#kartu-stok-table thead').on('input', '.column-filter', function() {
            const $input = $(this);
            const columnIndex = parseInt($input.data('column'));
            const searchValue = $input.val();
            
            // Use DataTables column search with regex disabled for exact partial matching
            // This provides client-side filtering without needing server changes
            table
                .column(columnIndex)
                .search(searchValue, false, true) // regex=false, smart=true
                .draw();
            
            // Update filter active indicator
            if (searchValue.length > 0) {
                $input.addClass('filter-active');
            } else {
                $input.removeClass('filter-active');
            }
            
            // Update stats after filtering
            updateStatsSummary();
        });
        
        // Prevent column header click when clicking on filter input
        $('.column-filter').on('click', function(e) {
            e.stopPropagation();
        });

        // ============================================
        // RESET FILTERS
        // ============================================
        $('#reset-filters').on('click', function() {
            // Reset global search
            $('#enhanced-search').val('');
            searchTerm = '';
            $('#clear-search').removeClass('visible');
            table.search('');
            
            // Reset ALL column filters (clear input values AND DataTables column search)
            $('.column-filter').each(function() {
                const $input = $(this);
                const columnIndex = parseInt($input.data('column'));
                $input.val('').removeClass('filter-active');
                table.column(columnIndex).search('');
            });
            
            // Reset date filter to default (SEMUA data)
            currentFilter = 'all';
            $('.quick-filter-btn').removeClass('active');
            $('.quick-filter-btn[data-filter="all"]').addClass('active');
            $('#active-filter-badge').text('Semua Data');
            $('#start_date').val('');
            $('#end_date').val('');
            
            // Reset page limit
            $('#page-limit').val('25');
            table.page.len(25);
            
            // Reset sorting to default
            $('#kartu-stok-table thead th').removeClass('sorted sorted-asc sorted-desc').attr('data-sort', 'none');
            
            // Reload data from server with default filter then redraw
            table.ajax.reload(function() {
                showNotification('✨ Semua filter telah direset!', 'success');
            });
        });

        // ============================================
        // PAGE JUMP
        // ============================================
        $('#page-jump-btn').on('click', function() {
            const pageNum = parseInt($('#page-jump-input').val());
            const maxPage = table.page.info().pages;
            
            if (pageNum >= 1 && pageNum <= maxPage) {
                table.page(pageNum - 1).draw('page');
            } else {
                showNotification('Nomor halaman tidak valid (1-' + maxPage + ')', 'warning');
            }
        });

        $('#page-jump-input').on('keypress', function(e) {
            if (e.which === 13) {
                $('#page-jump-btn').click();
            }
        });

        // ============================================
        // SORTING FUNCTIONALITY
        // ============================================
        $('#kartu-stok-table thead th[data-sort]').not('[data-column="0"]').not('[data-column="6"]').not('[data-column="8"]').on('click', function() {
            const column = $(this).data('column');
            const currentSort = $(this).attr('data-sort');
            
            // Reset all other columns
            $('#kartu-stok-table thead th').removeClass('sorted sorted-asc sorted-desc').attr('data-sort', 'none');
            
            // Toggle sort
            let newSort;
            if (currentSort === 'none' || currentSort === 'desc') {
                newSort = 'asc';
                $(this).addClass('sorted sorted-asc').attr('data-sort', 'asc');
            } else {
                newSort = 'desc';
                $(this).addClass('sorted sorted-desc').attr('data-sort', 'desc');
            }
            
            // Apply sort to DataTable
            table.order([column, newSort]).draw();
        });

        // ============================================
        // DATE FILTER HANDLERS
        // ============================================
        const filterLabels = {
            'all': 'Semua Data',
            'today': 'Hari Ini',
            'week': 'Minggu Ini',
            'month': 'Bulan Ini',
            'year': 'Tahun Ini',
            'custom': 'Rentang Kustom'
        };

        (function() {
            function handleFilterAction($el) {
                $('.quick-filter-btn').removeClass('active');
                $el.addClass('active');
                currentFilter = $el.data('filter');
                $('#active-filter-badge').text(filterLabels[currentFilter] || 'Semua Data');
                table.ajax.reload();
            }

            $(document).on('touchstart', '.filter-btn', function(e) {
                var $this = $(this);
                $this.data('touched', true);
                handleFilterAction($this);
            });

            $(document).on('click', '.filter-btn', function(e) {
                var $this = $(this);
                if ($this.data('touched')) { $this.data('touched', false); return; }
                handleFilterAction($this);
            });

            // Custom filter (apply) with touch support
            $(document).on('touchstart', '#apply-custom-filter', function(e) {
                $(this).data('touched', true);
                if ($('#start_date').val() && $('#end_date').val()) {
                    $('.quick-filter-btn').removeClass('active');
                    currentFilter = 'custom';
                    $('#active-filter-badge').text('Rentang Kustom');
                    table.ajax.reload();
                } else {
                    showNotification('Mohon pilih tanggal mulai dan tanggal akhir', 'warning');
                }
            });

            $(document).on('click', '#apply-custom-filter', function(e) {
                var $btn = $(this);
                if ($btn.data('touched')) { $btn.data('touched', false); return; }
                if ($('#start_date').val() && $('#end_date').val()) {
                    $('.quick-filter-btn').removeClass('active');
                    currentFilter = 'custom';
                    $('#active-filter-badge').text('Rentang Kustom');
                    table.ajax.reload();
                } else {
                    showNotification('Mohon pilih tanggal mulai dan tanggal akhir', 'warning');
                }
            });
        })();

        $('.datepicker').datepicker({
            format: 'yyyy-mm-dd',
            autoclose: true,
            todayHighlight: true
        });

        // Initialize Chart
        initStockChart();
    });

    // ============================================
    // HELPER FUNCTIONS
    // ============================================
    
    // Highlight search term in text
    function highlightText(text) {
        if (!searchTerm || searchTerm.length < 2 || !text) return text;
        
        const escapedTerm = searchTerm.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const regex = new RegExp('(' + escapedTerm + ')', 'gi');
        return text.toString().replace(regex, '<span class="search-highlight">$1</span>');
    }

    // Apply highlights after draw
    function highlightSearchResults() {
        if (!searchTerm || searchTerm.length < 2) return;
        
        $('#kartu-stok-table tbody td').each(function() {
            const text = $(this).text();
            if (text.toLowerCase().includes(searchTerm.toLowerCase())) {
                const html = $(this).html();
                if (!html.includes('search-highlight')) {
                    $(this).html(highlightText(text));
                }
            }
        });
    }

    // Update custom pagination
    function updateCustomPagination(settings) {
        const dt = new $.fn.dataTable.Api(settings);
        const info = dt.page.info();
        const start = info.start + 1;
        const end = info.end;
        const total = info.recordsTotal;
        const filtered = info.recordsDisplay;
        const currentPage = info.page + 1;
        const totalPages = info.pages;

        // Update info text
        $('#page-start').text(start > 0 ? start : 0);
        $('#page-end').text(end);
        $('#page-total').text(filtered);

        // Update page jump max
        $('#page-jump-input').attr('max', totalPages).val(currentPage);

        // Generate pagination buttons
        let paginationHtml = '';
        
        // Previous button
        paginationHtml += '<button type="button" class="page-btn" data-page="prev" ' + (currentPage === 1 ? 'disabled' : '') + '><i class="fa fa-chevron-left"></i></button>';
        
        // Page numbers
        const maxVisiblePages = 5;
        let startPage = Math.max(1, currentPage - Math.floor(maxVisiblePages / 2));
        let endPage = Math.min(totalPages, startPage + maxVisiblePages - 1);
        
        if (endPage - startPage < maxVisiblePages - 1) {
            startPage = Math.max(1, endPage - maxVisiblePages + 1);
        }

        if (startPage > 1) {
            paginationHtml += '<button type="button" class="page-btn" data-page="1">1</button>';
            if (startPage > 2) {
                paginationHtml += '<span style="padding: 0 8px; color: #94a3b8;">...</span>';
            }
        }

        for (let i = startPage; i <= endPage; i++) {
            paginationHtml += '<button type="button" class="page-btn ' + (i === currentPage ? 'active' : '') + '" data-page="' + i + '">' + i + '</button>';
        }

        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                paginationHtml += '<span style="padding: 0 8px; color: #94a3b8;">...</span>';
            }
            paginationHtml += '<button type="button" class="page-btn" data-page="' + totalPages + '">' + totalPages + '</button>';
        }

        // Next button
        paginationHtml += '<button type="button" class="page-btn" data-page="next" ' + (currentPage === totalPages || totalPages === 0 ? 'disabled' : '') + '><i class="fa fa-chevron-right"></i></button>';

        $('#pagination-controls').html(paginationHtml);

        // Bind click events
        $('#pagination-controls').off('click', '.page-btn').on('click', '.page-btn', function() {
            const page = $(this).data('page');
            if (page === 'prev') {
                dt.page('previous').draw('page');
            } else if (page === 'next') {
                dt.page('next').draw('page');
            } else {
                dt.page(page - 1).draw('page');
            }
        });
    }

    // Update stats summary
    function updateStatsSummary(settings) {
        const dt = new $.fn.dataTable.Api(settings);
        const info = dt.page.info();
        const data = dt.rows({search: 'applied'}).data();
        
        let totalMasuk = 0;
        let totalKeluar = 0;
        
        data.each(function(row) {
            totalMasuk += parseNumber(row.stok_masuk);
            totalKeluar += parseNumber(row.stok_keluar);
        });

        $('#stat-total').text(formatNumber(info.recordsTotal));
        $('#stat-filtered').text(formatNumber(info.recordsDisplay));
        $('#stat-masuk').text(formatNumber(totalMasuk));
        $('#stat-keluar').text(formatNumber(totalKeluar));
    }

    // Parse number from formatted string
    function parseNumber(str) {
        if (!str) return 0;
        // Remove HTML tags first
        str = str.toString().replace(/<[^>]*>/g, '');
        // Remove non-numeric characters except minus and dot
        const num = parseInt(str.replace(/[^0-9-]/g, ''));
        return isNaN(num) ? 0 : num;
    }

    // Format number with thousand separator
    function formatNumber(num) {
        return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    // Show notification - Enhanced with modern toast design
    function showNotification(message, type) {
        const colors = {
            success: { bg: '#10b981', icon: 'check-circle' },
            warning: { bg: '#f59e0b', icon: 'exclamation-triangle' },
            error: { bg: '#ef4444', icon: 'times-circle' },
            info: { bg: '#3b82f6', icon: 'info-circle' }
        };
        
        const config = colors[type] || colors.info;
        
        const notification = $('<div>')
            .addClass('toast-notification')
            .css({
                position: 'fixed',
                top: '20px',
                right: '20px',
                padding: '16px 24px',
                background: config.bg,
                color: '#fff',
                borderRadius: '12px',
                boxShadow: '0 10px 40px rgba(0,0,0,0.25), 0 0 0 1px rgba(255,255,255,0.1) inset',
                zIndex: 10000,
                fontWeight: 600,
                fontSize: '14px',
                display: 'flex',
                alignItems: 'center',
                gap: '12px',
                minWidth: '280px',
                maxWidth: '400px',
                transform: 'translateX(450px)',
                transition: 'all 0.4s cubic-bezier(0.4, 0, 0.2, 1)',
                backdropFilter: 'blur(10px)'
            })
            .html('<i class="fa fa-' + config.icon + '" style="font-size: 18px;"></i><span>' + message + '</span>')
            .appendTo('body');
        
        // Animate in
        setTimeout(function() {
            notification.css('transform', 'translateX(0)');
        }, 10);
        
        // Animate out
        setTimeout(function() {
            notification.css({
                transform: 'translateX(450px)',
                opacity: '0'
            });
            setTimeout(function() {
                notification.remove();
            }, 400);
        }, 3000);
    }

    // ============================================
    // STOCK CHART
    // ============================================
    function initStockChart() {
        const ctx = document.getElementById('stockChart').getContext('2d');
        const chartData = @json($stok_data['chart_data']);
        
        // Prepare data for chart
        const labels = [];
        const stokMasukData = [];
        const stokKeluarData = [];
        const stokSisaData = [];

        // Sort dates and prepare data
        const sortedDates = Object.keys(chartData).sort();
        
        sortedDates.forEach(date => {
            labels.push(new Date(date).toLocaleDateString('id-ID'));
            stokMasukData.push(chartData[date].masuk || 0);
            stokKeluarData.push(chartData[date].keluar || 0);
            stokSisaData.push(chartData[date].sisa || 0);
        });

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Stok Masuk',
                        data: stokMasukData,
                        borderColor: 'rgb(75, 192, 192)',
                        backgroundColor: 'rgba(75, 192, 192, 0.2)',
                        tension: 0.1
                    },
                    {
                        label: 'Stok Keluar',
                        data: stokKeluarData,
                        borderColor: 'rgb(255, 99, 132)',
                        backgroundColor: 'rgba(255, 99, 132, 0.2)',
                        tension: 0.1
                    },
                    {
                        label: 'Stok Tersisa',
                        data: stokSisaData,
                        borderColor: 'rgb(54, 162, 235)',
                        backgroundColor: 'rgba(54, 162, 235, 0.2)',
                        tension: 0.1,
                        yAxisID: 'y1',
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                scales: {
                    x: {
                        display: true,
                        title: {
                            display: true,
                            text: 'Tanggal'
                        }
                    },
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Jumlah Masuk/Keluar'
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Stok Tersisa'
                        },
                        grid: {
                            drawOnChartArea: false,
                        },
                    }
                },
                plugins: {
                    title: {
                        display: true,
                        text: 'Pergerakan Stok {{ $nama_barang }}'
                    },
                    legend: {
                        display: true,
                        position: 'top'
                    }
                }
            }
        });
    }
</script>
@endpush