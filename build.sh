#!/bin/bash
# Build and push FoleyTimePro to Docker Hub
# Usage: ./build.sh [version]   e.g.  ./build.sh 2.1.0

set -e

IMAGE="foleyit/foleytimepro"
VERSION="${1:-latest}"

echo "=================================="
echo "  FoleyTimePro Docker Build"
echo "=================================="
echo "  Image : $IMAGE:$VERSION"
echo "  Date  : $(date +%Y-%m-%d)"
echo "=================================="
echo ""

if ! docker info > /dev/null 2>&1; then
    echo "Error: Docker is not running"
    exit 1
fi

echo "Building..."
docker build \
    --platform linux/amd64 \
    -t "$IMAGE:$VERSION" \
    $([ "$VERSION" != "latest" ] && echo "-t $IMAGE:latest") \
    .

echo ""
echo "✓ Build complete: $IMAGE:$VERSION"
echo ""

read -p "Push to Docker Hub? (y/n): " push
if [ "$push" = "y" ]; then
    docker push "$IMAGE:$VERSION"
    [ "$VERSION" != "latest" ] && docker push "$IMAGE:latest"
    echo ""
    echo "✓ Pushed $IMAGE:$VERSION to Docker Hub"
fi

echo ""
echo "To deploy on a server:"
echo "  curl -fsSL https://raw.githubusercontent.com/TheTractorHacker/FoleyTimePro/main/Install/install.sh | bash"
