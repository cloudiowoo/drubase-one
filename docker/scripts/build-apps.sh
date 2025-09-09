#!/bin/bash

# Build and Deploy React Native Apps Script
# 用于构建和部署 React Native Apps 到 Docker 环境

set -e

echo "🚀 Building React Native Apps for Production..."

# Color codes for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Function to print colored output
print_status() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Change to apps/groups directory
cd ../apps/groups

# Check if node_modules exists
if [ ! -d "node_modules" ]; then
    print_status "Installing dependencies..."
    npm install
else
    print_status "Dependencies already installed"
fi

# Clean previous builds
print_status "Cleaning previous builds..."
rm -rf dist/ web-build/ .expo/

# Build web version
print_status "Building web version with Expo..."
npm run web:build

# Check if build was successful
if [ ! -d "dist" ]; then
    print_error "Build failed! dist directory not found."
    exit 1
fi

print_status "Build completed successfully!"

# Return to docker directory
cd ../../docker

# Build Docker image
print_status "Building Docker image..."
docker-compose build react-native-apps

# Optional: Start the service
read -p "Do you want to start the React Native Apps service now? (y/n): " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
    print_status "Starting React Native Apps service..."
    docker-compose up -d react-native-apps
    
    print_status "✅ React Native Apps is now running on http://localhost:3000"
    print_status "📊 You can check logs with: docker-compose logs -f react-native-apps"
else
    print_status "Build completed. Run 'docker-compose up -d react-native-apps' to start the service."
fi

echo -e "${GREEN}🎉 All done!${NC}"