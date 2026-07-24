# Copy images from Laravel public folder into WordPress uploads.
# Does NOT modify the Laravel project.

$laravelImages = "C:\Apache24\htdocs\ChristocentricRentals\ChristocentricRentals\public\images"
$wpUploads = "$PSScriptRoot\..\wp-content\uploads\christocentric"

if (-not (Test-Path $laravelImages)) {
    Write-Error "Laravel images not found: $laravelImages"
    exit 1
}

New-Item -ItemType Directory -Force -Path $wpUploads | Out-Null
Copy-Item -Path "$laravelImages\*" -Destination $wpUploads -Recurse -Force

Write-Host "Copied images to: $wpUploads"
Write-Host "In WordPress, product image paths may need updating to:"
Write-Host "  wp-content/uploads/christocentric/storage/..."
