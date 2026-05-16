#!/bin/bash
#
# FoleyTimePro Docker Deployment Script
# This script helps deploy FoleyTimePro using Docker
#

set -e

echo "=========================================="
echo "FoleyTimePro Docker Deployment"
echo "=========================================="
echo ""

# Check if Docker is installed
if ! command -v docker &> /dev/null; then
    echo "Error: Docker is not installed. Please install Docker first."
    echo "Visit: https://docs.docker.com/get-docker/"
    exit 1
fi

# Check if Docker Compose is installed
if ! command -v docker-compose &> /dev/null; then
    echo "Warning: Docker Compose is not installed."
    echo "You can still use 'docker run' commands manually."
    COMPOSE_AVAILABLE=false
else
    COMPOSE_AVAILABLE=true
fi

echo "Select deployment mode:"
echo "1) Development (single container, uses docker-compose.yml)"
echo "2) Production (full stack with SSL, uses docker-compose.prod.yml)"
echo "3) Simple (single container with docker run)"
echo ""
read -p "Enter choice [1-3]: " choice

case $choice in
    1)
        echo ""
        echo "Development Deployment"
        echo "----------------------"

        if [ ! -f .env ]; then
            echo "Creating .env file from example..."
            cp .env.example .env
            echo ""
            echo "Please edit .env file with your database credentials:"
            echo "  nano .env"
            read -p "Press Enter when ready to continue..."
        fi

        if [ "$COMPOSE_AVAILABLE" = true ]; then
            echo "Starting development stack..."
            docker-compose up -d
            echo ""
            echo "✓ Deployment complete!"
            echo "Access FoleyTimePro at: http://localhost:8080"
        else
            echo "Error: Docker Compose required for development mode"
            exit 1
        fi
        ;;

    2)
        echo ""
        echo "Production Deployment"
        echo "---------------------"

        if [ ! -f .env ]; then
            echo "Creating .env file from example..."
            cp .env.example .env
            echo ""
            echo "IMPORTANT: Edit .env file with your production settings:"
            echo "  - Set secure database passwords"
            echo "  - Set your domain name"
            echo "  - Set your email for SSL certificate"
            read -p "Press Enter when ready to continue..."
        fi

        if [ "$COMPOSE_AVAILABLE" = true ]; then
            read -p "Enter your domain name: " domain
            read -p "Enter your email for SSL certificate: " email

            # Update nginx config with domain
            sed -i "s/\${DOMAIN}/$domain/g" nginx/conf.d/foleytimepro.conf

            echo ""
            echo "Starting production stack..."
            docker-compose -f docker-compose.prod.yml up -d

            echo ""
            echo "Obtaining SSL certificate..."
            docker-compose -f docker-compose.prod.yml run --rm certbot certonly \
                --webroot \
                --webroot-path=/var/www/certbot \
                -d $domain \
                --email $email \
                --agree-tos \
                --no-eff-email

            echo ""
            echo "Restarting nginx with SSL..."
            docker-compose -f docker-compose.prod.yml restart nginx

            echo ""
            echo "✓ Production deployment complete!"
            echo "Access FoleyTimePro at: https://$domain"
        else
            echo "Error: Docker Compose required for production mode"
            exit 1
        fi
        ;;

    3)
        echo ""
        echo "Simple Deployment"
        echo "-----------------"

        read -p "Enter database host: " db_host
        read -p "Enter database name [timeclock]: " db_name
        db_name=${db_name:-timeclock}
        read -p "Enter database user [timeclock]: " db_user
        db_user=${db_user:-timeclock}
        read -sp "Enter database password: " db_pass
        echo ""
        read -p "Enter port to expose [8080]: " port
        port=${port:-8080}

        echo ""
        echo "Pulling latest image..."
        docker pull foleyit/foleytimepro:latest

        echo "Starting container..."
        docker run -d \
            --name foleytimepro \
            -p $port:80 \
            -e DB_HOST=$db_host \
            -e DB_NAME=$db_name \
            -e DB_USER=$db_user \
            -e DB_PASS=$db_pass \
            -e DB_TIMEZONE=America/Chicago \
            --restart unless-stopped \
            foleyit/foleytimepro:latest

        echo ""
        echo "✓ Deployment complete!"
        echo "Access FoleyTimePro at: http://localhost:$port"
        ;;

    *)
        echo "Invalid choice"
        exit 1
        ;;
esac

echo ""
echo "Useful commands:"
echo "  docker logs -f foleytimepro       # View logs"
echo "  docker exec -it foleytimepro bash # Access container shell"
echo "  docker restart foleytimepro       # Restart container"
echo "  docker stop foleytimepro          # Stop container"
echo ""
echo "See DOCKER_DEPLOYMENT.md for complete documentation"
