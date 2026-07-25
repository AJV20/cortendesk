@extends('layouts.app')

@section('title', 'Overview')

@section('content')
    {{-- Only shown to someone who can actually fix it. Everything listed here
         is a feature that exists and silently cannot run without a relay. --}}
    @if (auth()->user()?->consoleAllows('setting', 'rw') && ! app(\App\Services\MailSettings::class)->isEnabled())
        <div class="alert alert-warning d-flex align-items-start gap-2">
            <i class="ri-mail-close-line fs-20 mt-1"></i>
            <div class="flex-grow-1">
                <strong>Email is not configured.</strong>
                User invitations cannot be sent, and emailed sign-in verification codes cannot
                be delivered, so anyone locked out has to be recovered by an administrator by hand.
                <a href="{{ route('settings', ['tab' => 'email']) }}" class="alert-link">Set up SMTP</a>.
            </div>
        </div>
    @endif

    @livewire(App\Livewire\LiveStats::class)

    <div class="row">
        <div class="col-xl-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0"><i class="ri-line-chart-line me-1"></i>Connections — last 14 days</h5>
                    <a href="{{ route('logs.connections') }}" class="fs-13">View log</a>
                </div>
                <div class="card-body pt-1">
                    <div id="chart-connections" style="min-height: 300px;"></div>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="ri-computer-line me-1"></i>Device Platforms</h5>
                </div>
                <div class="card-body pt-1">
                    <div id="chart-platforms" style="min-height: 300px;"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-7">
            @livewire(App\Livewire\ActiveSessions::class)
        </div>
        <div class="col-xl-5">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0"><i class="ri-download-2-line me-1"></i>Client Versions</h5>
                    <a href="{{ route('devices') }}" class="fs-13">All devices</a>
                </div>
                <div class="card-body pt-1">
                    <div id="chart-versions" style="min-height: 260px;"></div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script src="{{ asset('assets/vendor/apexcharts/apexcharts.min.js') }}"></script>
<script>
(function () {
    "use strict";

    var connectionData = @json($connectionSeries);
    var platformData = @json($platformMix);
    var versionData = @json($versionCounts);

    // Categorical slots (validated against the Attex card surfaces in both
    // modes); chrome inks per mode. Series color follows the entity, fixed order.
    var THEMES = {
        dark: {
            series: ["#3987e5", "#d95926", "#199e70", "#c98500"],
            ink: "#c3c2b7", muted: "#898781",
            grid: "#3d4653", surface: "#313a46", mode: "dark"
        },
        light: {
            series: ["#2a78d6", "#eb6834", "#1baf7a", "#eda100"],
            ink: "#52514e", muted: "#898781",
            grid: "#e1e0d9", surface: "#ffffff", mode: "light"
        }
    };

    var charts = [];

    function theme() {
        var mode = document.documentElement.getAttribute("data-bs-theme") === "light" ? "light" : "dark";
        return THEMES[mode];
    }

    function baseOptions(t) {
        return {
            chart: { fontFamily: "inherit", foreColor: t.muted, toolbar: { show: false }, background: "transparent" },
            grid: { borderColor: t.grid, strokeDashArray: 3 },
            tooltip: { theme: t.mode },
            legend: { labels: { colors: t.ink }, markers: { size: 5 } },
            dataLabels: { enabled: false }
        };
    }

    function render() {
        charts.forEach(function (c) { c.destroy(); });
        charts = [];
        var t = theme();

        // Stacked daily connections: thin columns, rounded end on the stack top,
        // 2px surface gap between stacked segments.
        var conn = new ApexCharts(document.querySelector("#chart-connections"), Object.assign(baseOptions(t), {
            chart: Object.assign(baseOptions(t).chart, { type: "bar", height: 300, stacked: true }),
            series: connectionData.series,
            colors: t.series.slice(0, 3),
            plotOptions: { bar: { columnWidth: "45%", borderRadius: 4, borderRadiusApplication: "end", borderRadiusWhenStacked: "last" } },
            stroke: { show: true, width: 2, colors: [t.surface] },
            xaxis: { categories: connectionData.labels, axisBorder: { color: t.grid }, axisTicks: { show: false } },
            yaxis: { labels: { formatter: function (v) { return Math.round(v); } } },
            legend: Object.assign(baseOptions(t).legend, { position: "bottom" })
        }));

        // Platform donut: 2px surface ring between segments, legend carries identity.
        var plat = new ApexCharts(document.querySelector("#chart-platforms"), Object.assign(baseOptions(t), {
            chart: Object.assign(baseOptions(t).chart, { type: "donut", height: 300 }),
            series: platformData.values,
            labels: platformData.labels,
            colors: t.series.slice(0, platformData.labels.length),
            stroke: { width: 2, colors: [t.surface] },
            plotOptions: { pie: { donut: { size: "72%", labels: { show: true,
                total: { show: true, label: "Devices", color: t.ink, formatter: function (w) {
                    return w.globals.seriesTotals.reduce(function (a, b) { return a + b; }, 0);
                } },
                value: { color: t.ink } } } } },
            legend: Object.assign(baseOptions(t).legend, { position: "bottom" })
        }));

        // Version distribution: single series, single hue, no legend needed.
        var ver = new ApexCharts(document.querySelector("#chart-versions"), Object.assign(baseOptions(t), {
            chart: Object.assign(baseOptions(t).chart, { type: "bar", height: 260 }),
            series: [{ name: "Devices", data: versionData.values }],
            colors: [t.series[0]],
            plotOptions: { bar: { horizontal: true, barHeight: "45%", borderRadius: 4, borderRadiusApplication: "end" } },
            xaxis: { categories: versionData.labels, labels: { formatter: function (v) { return Math.round(v); } } },
            legend: { show: false },
            dataLabels: { enabled: true, style: { colors: [t.ink] }, formatter: function (v) { return v; }, offsetX: 24 }
        }));

        [conn, plat, ver].forEach(function (c) { charts.push(c); c.render(); });
    }

    render();

    // Re-render with the right mode colors when the Attex theme toggle flips.
    new MutationObserver(function (muts) {
        muts.forEach(function (m) { if (m.attributeName === "data-bs-theme") render(); });
    }).observe(document.documentElement, { attributes: true });
})();
</script>
@endpush
