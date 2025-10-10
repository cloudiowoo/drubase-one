#!/bin/bash

# BaaS Functions Service Startup Script

set -e

echo "🚀 Starting BaaS Functions Service..."

# Check if Node.js is installed
if ! command -v node &> /dev/null; then
    echo "❌ Node.js is not installed. Please install Node.js 18+ first."
    exit 1
fi

# Check Node.js version
NODE_VERSION=$(node -v | cut -d'v' -f2 | cut -d'.' -f1)
if [ "$NODE_VERSION" -lt 18 ]; then
    echo "❌ Node.js version 18+ is required. Current version: $(node -v)"
    exit 1
fi

# Create logs directory
mkdir -p logs

# Check if package.json exists
if [ ! -f "package.json" ]; then
    echo "❌ package.json not found. Please run this script from the service root directory."
    exit 1
fi

# Install dependencies if node_modules doesn't exist
if [ ! -d "node_modules" ]; then
    echo "📦 Installing dependencies..."
    npm install
fi

# Copy environment file if it doesn't exist
if [ ! -f ".env" ]; then
    if [ -f ".env.example" ]; then
        echo "📋 Copying .env.example to .env..."
        cp .env.example .env
        echo "⚠️  Please edit .env file with your configuration before running in production."
    fi
fi

# Check if running in development or production
if [ "${NODE_ENV:-development}" = "development" ]; then
    echo "🔧 Starting in development mode..."
    npm run dev
else
    echo "🏭 Starting in production mode..."
    npm start
fi