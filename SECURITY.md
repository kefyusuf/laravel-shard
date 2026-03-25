# Security Policy

## Supported Versions

We release patches for security vulnerabilities for the following versions:

| Version | Supported          |
| ------- | ------------------ |
| 2.x     | :white_check_mark: |
| 1.x     | :white_check_mark: |
| < 1.0   | :x:                |

## Reporting a Vulnerability

We take security vulnerabilities seriously. If you discover a security issue, please follow these steps:

### **DO NOT** open a public GitHub issue

Instead, please report security vulnerabilities to:

**Email:** kefyusuf@gmail.com

### What to Include

When reporting a vulnerability, please include:

1. **Description** of the vulnerability
2. **Steps to reproduce** the behavior
3. **Affected versions**
4. **Potential impact** of the vulnerability
5. **Suggested fix** (if you have one)

### Response Timeline

- We'll acknowledge receipt within **48 hours**
- We'll provide an initial assessment within **5 business days**
- We'll work on a fix and keep you updated on progress
- We'll publicly disclose the vulnerability after a fix is released

### Security Best Practices

When using this package, follow these best practices:

1. **Keep dependencies updated** - Regularly update Laravel, PHP, and this package
2. **Secure your Redis instance** - Use password authentication, firewall rules
3. **Validate shard access** - Ensure proper authorization before accessing shards
4. **Monitor logs** - Watch for unusual shard access patterns
5. **Use HTTPS** - Always use encrypted connections in production

### Known Security Considerations

- **Redis Security**: Ensure your Redis instance is not publicly accessible
- **Connection Credentials**: Store database credentials securely using environment variables
- **Shard Access**: Implement proper authorization checks in your application layer

## Hall of Fame

We appreciate security researchers who help keep Laravel Redis Sharding safe:

- (Your name could be here!)

Thank you for helping keep Laravel Redis Sharding and our users safe!
