#!/bin/bash
# Build and push FoleyTimePro to Docker Hub
# Usage: ./build.sh [version]   e.g.  ./build.sh 2.2.0
#        ./build.sh              (tags as latest)

set -e

IMAGE="foleyit/foleytimepro"
VERSION="${1:-latest}"
PLATFORMS="linux/amd64,linux/arm64"

echo "=================================="
echo "  FoleyTimePro Docker Build"
echo "=================================="
echo "  Image    : $IMAGE:$VERSION"
echo "  Platform : $PLATFORMS"
echo "  Date     : $(date +%Y-%m-%d)"
echo "=================================="
echo ""

if ! docker info > /dev/null 2>&1; then
    echo "Error: Docker is not running"
    exit 1
fi

# Build (multi-arch via buildx)
if [ "$VERSION" = "latest" ]; then
    TAGS="-t $IMAGE:latest"
else
    TAGS="-t $IMAGE:$VERSION -t $IMAGE:latest"
fi

echo "Building $IMAGE:$VERSION ..."
docker buildx build \
    --platform "$PLATFORMS" \
    $TAGS \
    --push \
    .

echo ""
echo "✓ Built and pushed $IMAGE:$VERSION"
echo ""
echo "Deploy on a new server:"
echo "  cd docker && cp .env.example .env && nano .env"
echo "  docker compose up -d"
