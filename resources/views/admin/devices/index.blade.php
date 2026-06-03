@extends('layouts.admin')
@section('content')
@php($deviceController = app(\App\Http\Controllers\Admin\DeviceController::class))
<div class="page-head">
    <div>
        <h1>SMS Devices</h1>
        <p class="hint">Android SMS reader devices and method permissions.</p>
    </div>
    <div class="actions">
        <button id="refreshDeviceStatus" class="btn secondary" type="button" data-url="{{ route('admin.devices.status') }}">Refresh status</button>
        <a class="btn" href="{{ route('admin.devices.create') }}">Add device</a>
    </div>
</div>

<div class="table-card"><div class="table-scroll">
    <table>
        <tr><th>Name</th><th>Allowed methods</th><th>Last sync</th><th>Status</th><th>Action</th></tr>
        @forelse($devices as $device)
            @php($status = $deviceController->deviceStatus($device))
            <tr data-device-id="{{ $device->id }}">
                <td><b>{{ $device->name }}</b></td>
                <td>{{ implode(', ', $device->allowed_methods ?? []) ?: 'All methods' }}</td>
                <td data-device-last-sync>{{ $deviceController->lastSyncLabel($device) }}</td>
                <td data-device-status>
                    <span class="pill {{ $status['class'] }}">{{ $status['label'] }}</span>
                </td>
                <td>
                    <div class="actions">
                        <a class="btn secondary" href="{{ route('admin.devices.edit',$device) }}">Edit</a>
                        <form method="post" action="{{ route('admin.devices.destroy',$device) }}" onsubmit="return confirm('Delete this device?')">
                            @csrf @method('delete')
                            <button class="btn danger">Delete</button>
                        </form>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="empty-row">No SMS devices found.</td></tr>
        @endforelse
    </table>
</div></div>

<script>
const refreshButton = document.getElementById('refreshDeviceStatus');
if (refreshButton) {
    refreshButton.addEventListener('click', async () => {
        const oldText = refreshButton.textContent;
        refreshButton.disabled = true;
        refreshButton.textContent = 'Refreshing...';
        try {
            const response = await fetch(refreshButton.dataset.url, {headers: {'Accept': 'application/json'}});
            const data = await response.json();
            (data.devices || []).forEach((device) => {
                const row = document.querySelector(`[data-device-id="${device.id}"]`);
                if (!row) return;
                const lastSync = row.querySelector('[data-device-last-sync]');
                const status = row.querySelector('[data-device-status]');
                if (lastSync) lastSync.textContent = device.last_sync;
                if (status) status.innerHTML = `<span class="pill ${device.status_class}">${device.status_label}</span>`;
            });
        } catch (error) {
            alert('Could not refresh device status.');
        } finally {
            refreshButton.disabled = false;
            refreshButton.textContent = oldText;
        }
    });
}
</script>
@endsection
