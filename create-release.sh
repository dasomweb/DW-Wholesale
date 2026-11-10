#!/bin/bash

# DW Wholesale - Release Script
# 사용법: ./create-release.sh [version]
# Usage: ./create-release.sh [version]

set -e

# 색상 정의
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# 버전 확인
if [ -z "$1" ]; then
    echo -e "${RED}Error: Version number is required${NC}"
    echo "Usage: ./create-release.sh [version]"
    echo "Example: ./create-release.sh 1.0.1"
    exit 1
fi

VERSION=$1
PLUGIN_FILE="dw-wholesale.php"
ZIP_NAME="dw-wholesale-${VERSION}.zip"

echo -e "${GREEN}Creating release for version ${VERSION}...${NC}"

# 1. 버전 번호 확인
echo -e "${YELLOW}Step 1: Checking current version...${NC}"
CURRENT_VERSION=$(grep -oP "Version: \K[0-9.]+" "$PLUGIN_FILE" | head -1)
echo "Current version in plugin file: $CURRENT_VERSION"

# 2. 버전 번호 업데이트 확인
read -p "Update version to $VERSION in $PLUGIN_FILE? (y/n) " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
    # 플러그인 헤더 버전 업데이트
    if [[ "$OSTYPE" == "darwin"* ]]; then
        sed -i '' "s/Version: [0-9.]*/Version: $VERSION/" "$PLUGIN_FILE"
        sed -i '' "s/'plugin_version'[[:space:]]*=> '[0-9.]*/'plugin_version'    => '$VERSION/" "$PLUGIN_FILE"
    else
        sed -i "s/Version: [0-9.]*/Version: $VERSION/" "$PLUGIN_FILE"
        sed -i "s/'plugin_version'[[:space:]]*=> '[0-9.]*/'plugin_version'    => '$VERSION/" "$PLUGIN_FILE"
    fi
    echo -e "${GREEN}Version updated to $VERSION${NC}"
else
    echo -e "${YELLOW}Skipping version update${NC}"
fi

# 3. Git 상태 확인
echo -e "${YELLOW}Step 2: Checking Git status...${NC}"
if [ -n "$(git status --porcelain)" ]; then
    echo "Uncommitted changes detected:"
    git status --short
    read -p "Commit changes? (y/n) " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        git add "$PLUGIN_FILE"
        git commit -m "Bump version to $VERSION"
        echo -e "${GREEN}Changes committed${NC}"
    fi
fi

# 4. ZIP 파일 생성
echo -e "${YELLOW}Step 3: Creating ZIP file...${NC}"
if [ -f "$ZIP_NAME" ]; then
    read -p "ZIP file $ZIP_NAME already exists. Overwrite? (y/n) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo -e "${YELLOW}Skipping ZIP creation${NC}"
        SKIP_ZIP=true
    else
        rm "$ZIP_NAME"
    fi
fi

if [ "$SKIP_ZIP" != true ]; then
    zip -r "$ZIP_NAME" . \
        -x "*.git*" \
        -x "*.github*" \
        -x "*.DS_Store" \
        -x "*.log" \
        -x "node_modules/*" \
        -x ".gitignore" \
        -x "README.md" \
        -x "DOMAIN-CHANGE-GUIDE.md" \
        -x "IMPLEMENTATION-SUMMARY.md" \
        -x "DEPLOYMENT-GUIDE.md" \
        -x "verify-domain-agnostic.php" \
        -x "create-release.sh" \
        -x "*.zip"
    
    echo -e "${GREEN}ZIP file created: $ZIP_NAME${NC}"
fi

# 5. Git 태그 생성
echo -e "${YELLOW}Step 4: Creating Git tag...${NC}"
TAG="v${VERSION}"
if git rev-parse "$TAG" >/dev/null 2>&1; then
    echo -e "${RED}Tag $TAG already exists${NC}"
    read -p "Delete and recreate? (y/n) " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        git tag -d "$TAG"
        git push origin :refs/tags/"$TAG" 2>/dev/null || true
    else
        echo -e "${YELLOW}Skipping tag creation${NC}"
        SKIP_TAG=true
    fi
fi

if [ "$SKIP_TAG" != true ]; then
    git tag "$TAG"
    echo -e "${GREEN}Tag $TAG created${NC}"
fi

# 6. 푸시 확인
echo -e "${YELLOW}Step 5: Ready to push${NC}"
echo "The following will be pushed:"
echo "  - Commits (if any)"
echo "  - Tag: $TAG"
read -p "Push to remote? (y/n) " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
    git push origin main
    if [ "$SKIP_TAG" != true ]; then
        git push origin "$TAG"
    fi
    echo -e "${GREEN}Pushed to remote${NC}"
    echo -e "${GREEN}GitHub Actions will automatically create the release!${NC}"
else
    echo -e "${YELLOW}Not pushing. You can push manually later:${NC}"
    echo "  git push origin main"
    echo "  git push origin $TAG"
fi

# 7. 요약
echo ""
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}Release Summary${NC}"
echo -e "${GREEN}========================================${NC}"
echo "Version: $VERSION"
echo "Tag: $TAG"
if [ -f "$ZIP_NAME" ]; then
    echo "ZIP File: $ZIP_NAME ($(du -h "$ZIP_NAME" | cut -f1))"
fi
echo ""
echo "Next steps:"
echo "1. Check GitHub Actions for automatic release creation"
echo "2. Or manually create release at: https://github.com/dasomweb/DW-Wholesale/releases/new"
if [ -f "$ZIP_NAME" ]; then
    echo "3. Upload $ZIP_NAME to the release"
fi
echo ""


