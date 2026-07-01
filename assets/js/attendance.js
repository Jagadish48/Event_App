// Employee Attendance System - Complete Camera-Based Implementation
let cameraStream = null;
let capturedImageData = null;
let currentAttendanceType = '';
let currentFacingMode = 'user'; // 'user' = front camera, 'environment' = back camera
let employeeData = null;
let locationData = null;

// Initialize attendance system with employee data
function initAttendanceSystem(employeeInfo) {
    console.log('[initAttendanceSystem] Called with:', employeeInfo);
    employeeData = employeeInfo;
    console.log('[initAttendanceSystem] employeeData set:', employeeData);
}

// Helper: Promise timeout wrapper
const withTimeout = (promise, ms, timeoutError) => {
    return Promise.race([
        promise,
        new Promise((_, reject) => setTimeout(() => reject(new Error(timeoutError)), ms))
    ]);
};

// Get current location with high accuracy
async function getCurrentLocation() {
    return new Promise((resolve, reject) => {
        if (!navigator.geolocation) {
            reject(new Error('Geolocation not supported by browser'));
            return;
        }
        
        navigator.geolocation.getCurrentPosition(
            (position) => {
                const coords = {
                    latitude: position.coords.latitude,
                    longitude: position.coords.longitude,
                    accuracy: position.coords.accuracy
                };
                
                // Try to get address from OpenStreetMap Nominatim API
                fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${coords.latitude}&lon=${coords.longitude}`)
                    .then(res => res.json())
                    .then(data => {
                        coords.address = data.display_name ? data.display_name.split(', ').slice(0, 3).join(', ') : '';
                        resolve(coords);
                    })
                    .catch(() => {
                        coords.address = '';
                        resolve(coords);
                    });
            },
            (error) => {
                let message = 'Location access denied';
                if (error.code === error.POSITION_UNAVAILABLE) {
                    message = 'Location unavailable';
                } else if (error.code === error.TIMEOUT) {
                    message = 'Location request timed out';
                }
                reject(new Error(message));
            },
            {
                enableHighAccuracy: true,
                timeout: 15000,
                maximumAge: 0
            }
        );
    });
}

// Stop any active camera streams
function stopCamera() {
    if (cameraStream) {
        cameraStream.getTracks().forEach(track => track.stop());
        cameraStream = null;
    }
}

// Start camera with specific facing mode
async function startCamera(facingMode = 'user') {
    console.log(`[startCamera] Requesting camera with facingMode: ${facingMode}`);
    try {
        stopCamera();
        
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            console.error('[startCamera] navigator.mediaDevices.getUserMedia is undefined');
            throw new Error('Camera API not supported (HTTPS required or browser unsupported).');
        }

        const constraints = {
            video: {
                facingMode: facingMode,
                width: { ideal: 1280 },
                height: { ideal: 720 }
            }
        };
        console.log('[startCamera] Using constraints:', constraints);
        
        const stream = await withTimeout(
            navigator.mediaDevices.getUserMedia(constraints),
            8000,
            'Camera request timed out (user took too long or system blocked it)'
        );
        console.log('[startCamera] Stream obtained successfully:', stream.id);
        const video = document.getElementById('attendanceCameraVideo');
        
        if (video) {
            video.srcObject = stream;
            video.style.display = 'block';
            console.log('[startCamera] Attempting to play video stream...');
            
            // Wait for video to load metadata to ensure dimensions are available
            await new Promise((resolve) => {
                video.onloadedmetadata = () => {
                    console.log(`[startCamera] Video metadata loaded. Dimensions: ${video.videoWidth}x${video.videoHeight}`);
                    resolve();
                };
            });
            
            await video.play();
            console.log('[startCamera] Video playing successfully.');
        } else {
            console.error('[startCamera] Video element not found in DOM.');
        }
        
        document.getElementById('attendanceCameraPlaceholder').style.display = 'none';
        document.getElementById('attendanceSwitchFrontBtn').style.display = facingMode === 'environment' ? 'inline-block' : 'none';
        document.getElementById('attendanceSwitchBackBtn').style.display = facingMode === 'user' ? 'inline-block' : 'none';
        document.getElementById('attendanceCaptureBtn').style.display = 'inline-block';
        document.getElementById('attendanceRetakeBtn').style.display = 'none';
        document.getElementById('attendanceSubmitBtn').style.display = 'none';
        
        cameraStream = stream;
        currentFacingMode = facingMode;
    } catch (error) {
        console.error('[startCamera] Camera error caught:', error.name, error.message);
        handleCameraFallback(error);
    }
}

// Fallback for Android WebView / no HTTPS
function handleCameraFallback(error) {
    console.log('[handleCameraFallback] Triggered with error:', error);
    let errorMsg = typeof error === 'string' ? error : (error.message || 'Unknown error');
    
    // Map common DOMExceptions to user-friendly messages
    if (error.name === 'NotAllowedError') {
        errorMsg = 'Camera permission denied by user or device settings.';
    } else if (error.name === 'NotFoundError') {
        errorMsg = 'No camera device found on this device.';
    } else if (error.name === 'NotReadableError') {
        errorMsg = 'Camera is already in use by another application.';
    }
    
    console.warn(`[handleCameraFallback] Mapped error message: ${errorMsg}`);
    const placeholder = document.getElementById('attendanceCameraPlaceholder');
    if (placeholder) {
        placeholder.innerHTML = `<i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i><p class="text-muted fs-5">${errorMsg}</p>`;
        placeholder.style.display = 'flex';
    }
    
    let fallbackInput = document.getElementById('attendanceFallbackInput');
    if (!fallbackInput) {
        fallbackInput = document.createElement('input');
        fallbackInput.type = 'file';
        fallbackInput.accept = 'image/*';
        fallbackInput.capture = 'environment';
        fallbackInput.id = 'attendanceFallbackInput';
        fallbackInput.style.display = 'none';
        
        fallbackInput.addEventListener('change', function(e) {
            if (e.target.files && e.target.files[0]) {
                const reader = new FileReader();
                reader.onload = function(event) {
                    const img = new Image();
                    img.onload = async function() {
                        const canvas = document.getElementById('attendanceCameraCanvas');
                        const context = canvas.getContext('2d');
                        
                        canvas.width = img.width;
                        canvas.height = img.height;
                        context.drawImage(img, 0, 0);
                        
                        await addAttendanceOverlay(canvas, context);
                        capturedImageData = canvas.toDataURL('image/jpeg', 0.9);
                        
                        const previewImg = document.getElementById('attendanceCameraPreview');
                        if (previewImg) {
                            previewImg.src = capturedImageData;
                            previewImg.style.display = 'block';
                        }
                        
                        document.getElementById('attendanceCaptureBtn').style.display = 'none';
                        document.getElementById('attendanceRetakeBtn').style.display = 'inline-block';
                        document.getElementById('attendanceSubmitBtn').style.display = 'inline-block';
                        
                        const fallbackBtn = document.getElementById('attendanceFallbackBtn');
                        if (fallbackBtn) fallbackBtn.style.display = 'none';
                    };
                    img.src = event.target.result;
                };
                reader.readAsDataURL(e.target.files[0]);
            }
        });
        document.body.appendChild(fallbackInput);
    }
    
    let fallbackBtn = document.getElementById('attendanceFallbackBtn');
    if (!fallbackBtn) {
        fallbackBtn = document.createElement('button');
        fallbackBtn.id = 'attendanceFallbackBtn';
        fallbackBtn.className = 'btn btn-primary mt-3';
        fallbackBtn.innerHTML = '<i class="fas fa-camera me-2"></i>Open System Camera';
        fallbackBtn.onclick = function() { fallbackInput.click(); };
        
        const placeholder = document.getElementById('attendanceCameraPlaceholder');
        if (placeholder && placeholder.parentNode) {
            placeholder.parentNode.appendChild(fallbackBtn);
        }
    }
    fallbackBtn.style.display = 'inline-block';
    
    document.getElementById('attendanceSwitchFrontBtn').style.display = 'none';
    document.getElementById('attendanceSwitchBackBtn').style.display = 'none';
    document.getElementById('attendanceCaptureBtn').style.display = 'none';
    document.getElementById('attendanceRetakeBtn').style.display = 'none';
    document.getElementById('attendanceSubmitBtn').style.display = 'none';
    
    const previewImg = document.getElementById('attendanceCameraPreview');
    if (previewImg) previewImg.style.display = 'none';
    
    if (typeof showToast === 'function') {
        showToast('warning', 'Live camera access failed. Please use the fallback button to take a photo. (' + (errorMessage || '') + ')');
    }
}

// Capture photo from camera
async function capturePhoto() {
    console.log('[capturePhoto] STARTED');
    const video = document.getElementById('attendanceCameraVideo');
    const canvas = document.getElementById('attendanceCameraCanvas');
    console.log(`[capturePhoto] Elements found - video: ${!!video}, canvas: ${!!canvas}`);
    
    if (!video || video.videoWidth === 0 || video.videoHeight === 0) {
        console.error('[capturePhoto] Video is not playing or has 0 dimensions', {
            videoWidth: video ? video.videoWidth : null,
            videoHeight: video ? video.videoHeight : null
        });
        if (typeof showToast === 'function') showToast('danger', 'Camera stream is not ready or failed to play.');
        return;
    }

    const context = canvas.getContext('2d');
    
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    console.log('[capturePhoto] Canvas initialized with dimensions:', canvas.width, 'x', canvas.height);
    context.drawImage(video, 0, 0);
    
    // Add overlay with employee details, date, time, location
    console.log('[capturePhoto] Adding overlay text...');
    await addAttendanceOverlay(canvas, context);
    
    capturedImageData = canvas.toDataURL('image/jpeg', 0.9);
    console.log(`[capturePhoto] Base64 image generated. Length: ${capturedImageData.length}`);
    
    if (capturedImageData.length < 1000) {
        console.warn('[capturePhoto] Warning: Generated image data is unusually small, possibly corrupt or empty.');
    }
    
    // Show preview
    const previewImg = document.getElementById('attendanceCameraPreview');
    if (previewImg) {
        previewImg.src = capturedImageData;
        previewImg.style.display = 'block';
    }
    
    video.style.display = 'none';
    document.getElementById('attendanceCameraPlaceholder').style.display = 'none';
    document.getElementById('attendanceSwitchFrontBtn').style.display = 'none';
    document.getElementById('attendanceSwitchBackBtn').style.display = 'none';
    document.getElementById('attendanceCaptureBtn').style.display = 'none';
    document.getElementById('attendanceRetakeBtn').style.display = 'inline-block';
    document.getElementById('attendanceSubmitBtn').style.display = 'inline-block';
    console.log('[capturePhoto] DONE');
}

// Add detailed overlay to captured photo
async function addAttendanceOverlay(canvas, context) {
    const now = new Date();
    const dateStr = now.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
    const timeStr = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
    
    // Build overlay text lines
    const lines = [];
    lines.push(employeeData.name || 'Employee');
    if (employeeData.id) lines.push('ID: ' + employeeData.id);
    lines.push('Date: ' + dateStr);
    lines.push('Time: ' + timeStr);
    
    if (locationData) {
        lines.push('Lat: ' + locationData.latitude.toFixed(6));
        lines.push('Lng: ' + locationData.longitude.toFixed(6));
        if (locationData.address) lines.push('Location: ' + locationData.address);
    }
    
    // Configure text style - compact professional design
    const baseFontSize = Math.min(14, Math.floor(canvas.width / 80));
    const nameFontSize = Math.min(16, Math.floor(canvas.width / 70));
    const lineHeight = baseFontSize * 1.2;
    const padding = 8;
    const margin = 10;
    const maxWidthPercent = 0.4; // 40% of canvas width
    const maxBoxWidth = canvas.width * maxWidthPercent;
    
    // Draw name with bold, slightly larger font
    context.font = 'bold ' + nameFontSize + 'px Arial, sans-serif';
    context.textAlign = 'left';
    context.textBaseline = 'top';
    
    // Calculate box size, wrapping text if needed
    const wrappedLines = [];
    const tempContext = canvas.getContext('2d');
    
    // First, wrap the address line if it's too long
    for (let i = 0; i < lines.length; i++) {
        const line = lines[i];
        const fontSize = i === 0 ? nameFontSize : baseFontSize;
        tempContext.font = (i === 0 ? 'bold ' : '') + fontSize + 'px Arial, sans-serif';
        
        if (i === 0) {
            // Name - wrap if needed
            let remaining = line;
            while (remaining.length > 0) {
                let guess = remaining.length;
                while (guess > 0 && tempContext.measureText(remaining.substring(0, guess)).width > maxBoxWidth - padding * 2) {
                    guess--;
                }
                if (guess === 0) guess = 1;
                wrappedLines.push({ text: remaining.substring(0, guess), size: fontSize });
                remaining = remaining.substring(guess);
            }
        } else if (line.startsWith('Location:')) {
            // Address line - wrap
            const label = 'Location: ';
            const addressPart = line.substring(label.length);
            let currentLine = label;
            for (let word of addressPart.split(' ')) {
                const testLine = currentLine + (currentLine.length > label.length ? ' ' : '') + word;
                if (tempContext.measureText(testLine).width > maxBoxWidth - padding * 2) {
                    wrappedLines.push({ text: currentLine, size: baseFontSize });
                    currentLine = word;
                } else {
                    currentLine = testLine;
                }
            }
            wrappedLines.push({ text: currentLine, size: baseFontSize });
        } else {
            wrappedLines.push({ text: line, size: baseFontSize });
        }
    }
    
    // Calculate total height
    let totalTextHeight = 0;
    for (const line of wrappedLines) {
        totalTextHeight += line.size * 1.2;
    }
    
    const boxHeight = totalTextHeight + padding * 2;
    let maxTextWidth = 0;
    for (const line of wrappedLines) {
        tempContext.font = (line.size === nameFontSize ? 'bold ' : '') + line.size + 'px Arial, sans-serif';
        const w = tempContext.measureText(line.text).width;
        if (w > maxTextWidth) maxTextWidth = w;
    }
    
    const boxWidth = Math.min(maxBoxWidth, maxTextWidth + padding * 2);
    
    // Position at bottom-left with margin
    const x = margin;
    const y = canvas.height - boxHeight - margin;
    
    // Draw semi-transparent dark background with rounded corners (simulated)
    context.fillStyle = 'rgba(0, 0, 0, 0.55)';
    context.beginPath();
    const radius = 6;
    context.moveTo(x + radius, y);
    context.lineTo(x + boxWidth - radius, y);
    context.quadraticCurveTo(x + boxWidth, y, x + boxWidth, y + radius);
    context.lineTo(x + boxWidth, y + boxHeight - radius);
    context.quadraticCurveTo(x + boxWidth, y + boxHeight, x + boxWidth - radius, y + boxHeight);
    context.lineTo(x + radius, y + boxHeight);
    context.quadraticCurveTo(x, y + boxHeight, x, y + boxHeight - radius);
    context.lineTo(x, y + radius);
    context.quadraticCurveTo(x, y, x + radius, y);
    context.closePath();
    context.fill();
    
    // Draw text
    context.fillStyle = '#ffffff';
    let cursorY = y + padding;
    for (const line of wrappedLines) {
        context.font = (line.size === nameFontSize ? 'bold ' : '') + line.size + 'px Arial, sans-serif';
        context.fillText(line.text, x + padding, cursorY);
        cursorY += line.size * 1.2;
    }
}

// Open attendance modal and start the process
async function openAttendanceModal(type) {
    console.log('[openAttendanceModal] STARTED, type:', type);
    currentAttendanceType = type;
    capturedImageData = null;
    locationData = null;
    currentFacingMode = 'user';
    
    const modal = document.getElementById('attendanceCameraModal');
    console.log('[openAttendanceModal] Modal element:', !!modal);
    if (!modal) return;
    
    // Show modal
    console.log('[openAttendanceModal] Showing modal');
    const bsModal = new bootstrap.Modal(modal);
    bsModal.show();
    
    // Reset placeholder UI
    const placeholder = document.getElementById('attendanceCameraPlaceholder');
    if (placeholder) {
        placeholder.innerHTML = '<i class="fas fa-spinner fa-4x fa-spin text-muted mb-3"></i><p class="text-muted fs-5">Requesting permissions and starting camera...</p>';
        placeholder.style.display = 'flex';
    }

    // First request location permission
    try {
        console.log('[openAttendanceModal] Getting location');
        locationData = await withTimeout(getCurrentLocation(), 5000, 'Location request timed out');
        console.log('[openAttendanceModal] Location obtained:', locationData);
    } catch (error) {
        console.warn('Location failed or timed out:', error);
        locationData = null;
        if (typeof showToast === 'function') {
            showToast('warning', 'Could not get exact location. Proceeding with photo capture.');
        }
    }
    
    // Start camera (works for both desktop and mobile now)
    console.log('[openAttendanceModal] Starting camera');
    await startCamera('user');
    console.log('[openAttendanceModal] DONE');
}

// Close the modal and clean up
function closeAttendanceModal() {
    stopCamera();
    capturedImageData = null;
    locationData = null;
    
    const modal = document.getElementById('attendanceCameraModal');
    if (modal) {
        const bsModal = bootstrap.Modal.getInstance(modal);
        if (bsModal) bsModal.hide();
    }
    
    // Reset modal UI
    document.getElementById('attendanceCameraVideo').style.display = 'none';
    document.getElementById('attendanceCameraPreview').style.display = 'none';
    document.getElementById('attendanceCameraPlaceholder').style.display = 'block';
    document.getElementById('attendanceSwitchFrontBtn').style.display = 'none';
    document.getElementById('attendanceSwitchBackBtn').style.display = 'none';
    document.getElementById('attendanceCaptureBtn').style.display = 'none';
    document.getElementById('attendanceRetakeBtn').style.display = 'none';
    document.getElementById('attendanceSubmitBtn').style.display = 'none';
    
    const fallbackBtn = document.getElementById('attendanceFallbackBtn');
    if (fallbackBtn) {
        fallbackBtn.style.display = 'none';
    }
}

// Submit attendance to server
async function submitAttendance() {
    console.log('[submitAttendance] STARTED');
    console.log('[submitAttendance] capturedImageData:', !!capturedImageData);
    console.log('[submitAttendance] locationData:', locationData);
    if (!capturedImageData) {
        showToast('danger', 'Please capture a photo first');
        return;
    }
    
    if (!locationData) {
        console.log('[submitAttendance] location okay');
    }
    
    try {
        console.log('[submitAttendance] Show loading');
        showLoading();
        
        // Prepare form data
        const formData = new FormData();
        formData.append('type', currentAttendanceType);
        formData.append('latitude', locationData ? locationData.latitude : '');
        formData.append('longitude', locationData ? locationData.longitude : '');
        formData.append('address', (locationData && locationData.address) ? locationData.address : '');
        formData.append('camera_type', currentFacingMode === 'user' ? 'front' : 'back');
        
        // Convert base64 image to Blob object (more compatible than File constructor)
        console.log('[submitAttendance] Converting image dataURL to Blob');
        const imageBlob = dataURLtoBlob(capturedImageData);
        formData.append('image', imageBlob, 'attendance_' + Date.now() + '.jpg');
        console.log('[submitAttendance] Image Blob size:', imageBlob.size);
        console.log('[submitAttendance] FormData entries:', Array.from(formData.entries()));
        
        console.log('[submitAttendance] Sending request to attendance_process.php');
        const response = await fetch('attendance_process.php', {
            method: 'POST',
            body: formData
        });
        
        console.log('[submitAttendance] Response status:', response.status);
        const responseText = await response.text();
        console.log('[submitAttendance] Response text:', responseText);
        
        let result;
        try {
            result = JSON.parse(responseText);
        } catch (parseErr) {
            console.error('[submitAttendance] JSON parse error:', parseErr);
            result = { success: false, message: 'Invalid server response' };
        }
        
        console.log('[submitAttendance] Result:', result);
        
        if (result.success) {
            showToast('success', result.message);
            closeAttendanceModal();
            
            // Reload page after short delay
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        } else {
            showToast('danger', result.message || result.error || 'Failed to mark attendance');
        }
    } catch (error) {
        console.error('[submitAttendance] Error:', error);
        showToast('danger', 'Error submitting attendance: ' + error.message);
    } finally {
        hideLoading();
    }
}

// Helper: Convert data URL to Blob object (High compatibility for older WebViews)
function dataURLtoBlob(dataURL) {
    console.log('[dataURLtoBlob] Parsing dataURL string...');
    const arr = dataURL.split(',');
    const mimeMatch = arr[0].match(/:(.*?);/);
    const mime = mimeMatch ? mimeMatch[1] : 'image/jpeg';
    const bstr = atob(arr[1]);
    let n = bstr.length;
    const u8arr = new Uint8Array(n);
    while (n--) {
        u8arr[n] = bstr.charCodeAt(n);
    }
    console.log(`[dataURLtoBlob] Created Blob with size ${u8arr.length} bytes, type: ${mime}`);
    return new Blob([u8arr], { type: mime });
}

// Attach event listeners when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    console.log('[attendance.js] DOMContentLoaded');
    // Camera controls
    const switchFrontBtn = document.getElementById('attendanceSwitchFrontBtn');
    console.log('[attendance.js] switchFrontBtn:', !!switchFrontBtn);
    if (switchFrontBtn) {
        switchFrontBtn.addEventListener('click', () => {
            console.log('[attendance.js] Front Camera clicked');
            startCamera('user');
        });
    }
    
    const switchBackBtn = document.getElementById('attendanceSwitchBackBtn');
    console.log('[attendance.js] switchBackBtn:', !!switchBackBtn);
    if (switchBackBtn) {
        switchBackBtn.addEventListener('click', () => {
            console.log('[attendance.js] Back Camera clicked');
            startCamera('environment');
        });
    }
    
    const captureBtn = document.getElementById('attendanceCaptureBtn');
    console.log('[attendance.js] captureBtn:', !!captureBtn);
    if (captureBtn) {
        captureBtn.addEventListener('click', () => {
            console.log('[attendance.js] Capture clicked');
            capturePhoto();
        });
    }
    
    const retakeBtn = document.getElementById('attendanceRetakeBtn');
    console.log('[attendance.js] retakeBtn:', !!retakeBtn);
    if (retakeBtn) {
        retakeBtn.addEventListener('click', () => {
            console.log('[attendance.js] Retake clicked');
            startCamera(currentFacingMode);
        });
    }
    
    const submitBtn = document.getElementById('attendanceSubmitBtn');
    console.log('[attendance.js] submitBtn:', !!submitBtn);
    if (submitBtn) {
        submitBtn.addEventListener('click', (e) => {
            console.log('Submit button clicked');
            console.log('[attendance.js] Submit clicked');
            e.preventDefault();
            submitAttendance();
        });
    }
    
    // Close modal when hidden
    const cameraModalEl = document.getElementById('attendanceCameraModal');
    if (cameraModalEl) {
        cameraModalEl.addEventListener('hidden.bs.modal', () => {
            stopCamera();
            capturedImageData = null;
            locationData = null;
        });
    }
});
