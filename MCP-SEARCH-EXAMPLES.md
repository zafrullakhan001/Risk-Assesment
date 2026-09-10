# MCP SharePoint Catalog - Search Examples & Quick Reference

## 🔍 What You Can Ask Your AI Assistant

### Basic Search Queries

**Simple keyword search:**
```
"Search the SharePoint catalog for projects containing 'encore'"
"Find projects with 'architecture' in the name"
"Search for any project or file mentioning 'database migration'"
"Look for folders related to 'risk assessment'"
```

---

## 📁 Search by File Type

**Find specific document types:**
```
"Find all projects that have PDF files"
"Search for projects containing Visio diagrams"
"Show me projects with Excel spreadsheets (XLSX files)"
"Find projects that include PowerPoint presentations"
"Which projects have Word documents?"
"Search for projects with compressed archives (ZIP files)"
```

**Supported file types:**
- `ext:pdf` - PDF documents
- `ext:docx` or `ext:doc` - Word documents
- `ext:xlsx` or `ext:xls` - Excel spreadsheets
- `ext:pptx` or `ext:ppt` - PowerPoint presentations
- `ext:vsdx` or `ext:vsd` - Visio diagrams
- `ext:msg` or `ext:eml` - Email files
- `ext:zip` or `ext:7z` or `ext:rar` - Archives

---

## 👤 Search by Person

**Find work by specific people:**
```
"What projects were modified by John Smith?"
"Find all folders created by Jane Doe"
"Show me projects last modified by the architecture team"
"Which projects has Sarah worked on recently?"
"Search for documents where Modified By contains 'Smith'"
```

**Format:** Use `person:"Last, First"` for exact matches

---

## 🏷️ Search by Tags

**Find tagged projects:**
```
"Find projects tagged as 'priority'"
"Show me all projects with the 'completed' tag"
"Search for projects tagged 'infrastructure' or 'security'"
"What tags are available in the catalog?"
"List the most commonly used tags"
"Find projects with both 'active' and 'urgent' tags"
```

---

## 🎯 Advanced Search with Operators

**Combine multiple criteria:**
```
"Find projects with 'network' but exclude 'legacy'"
"Search for exact phrase 'data center migration'"
"Find projects with extension:pdf AND tag:priority"
"Show me projects in the path 'drawings' folder"
"Search for person:'Smith, John' AND ext:docx"
"Find projects modified this year that have Visio files"
```

**Operators:**
| Operator | Example | Description |
|----------|---------|-------------|
| `tag:` | `tag:priority` | Filter by tag |
| `ext:` | `ext:pdf` | Filter by extension |
| `type:` | `type:visio` | Filter by type |
| `person:` | `person:"Smith, John"` | Filter by person |
| `path:` | `path:drawings` | Filter by path |
| `has:` | `has:pdf` | Must contain type |
| `"..."` | `"exact phrase"` | Exact match |
| `-` | `-legacy` | Exclude term |
| `AND` | Implicit between terms |

---

## 📊 Browse and Explore

**Discover what's available:**
```
"List all SharePoint catalog sources available"
"Show me the first 10 projects in the default catalog"
"What's in the Public catalog versus Private catalog?"
"Get detailed information about Project Alpha including all files"
"Show me the folder structure for 'Infrastructure Upgrade' project"
"Browse the architecture catalog"
```

---

## 📈 Statistics and Overview

**Get catalog insights:**
```
"Give me statistics about the SharePoint catalog"
"How many projects are in each catalog source?"
"When was each catalog last synced?"
"What's the total number of items across all catalogs?"
"Show me catalog health and sync status"
"Which catalog has the most projects?"
```

---

## 🔗 Combining Multiple Queries

**Multi-step queries:**
```
"Search for projects with PDF files, then show me the one modified most recently"
"Find all projects tagged 'active', then list those modified this month"
"Get catalog stats, then search for the largest project"
"List all sources, then search the Public catalog for 'design' projects"
"Find projects by John Smith, then show which have Visio diagrams"
```

---

## 📄 Project Details

**Deep dive into specific projects:**
```
"Show me all files in the 'Customer Portal' project"
"What's the folder structure of 'Infrastructure Upgrade'?"
"Get details about 'Q4 Planning' including file sizes and dates"
"List all documents in 'Architecture Review 2026' with their URLs"
"Show me nested folders in the 'Network Redesign' project"
"What's the last modified date for 'Security Assessment'?"
```

---

## 💡 Real-World Examples

### Scenario 1: Finding Architecture Documents
```
"Search for projects with 'architecture' that have Visio diagrams and are tagged 'current'"
```

### Scenario 2: Recent Work by Team Member
```
"Find all projects modified by Sarah Johnson in the last 30 days"
```

### Scenario 3: Compliance Check
```
"List all projects that have PDF files but no Word documents"
```

### Scenario 4: Cross-Catalog Search
```
"Search both Public and Private catalogs for projects containing 'security audit'"
```

### Scenario 5: Finding Stale Projects
```
"Show me projects not modified in the last 90 days that are tagged 'active'"
```

### Scenario 6: Document Inventory
```
"Get statistics on file types across all projects in the Infrastructure catalog"
```

---

## 📋 Search Operator Reference Card

### Quick Syntax Guide

```
Basic:        risk assessment
Tag:          tag:priority
Extension:    ext:pdf
Type:         type:excel
Person:       person:"Smith, John"
Path:         path:drawings
Has:          has:visio
Exact:        "exact phrase here"
Exclude:      -legacy
Combined:     tag:priority ext:pdf -archived
```

### Pattern Examples

```
Find PDFs tagged priority:
  tag:priority ext:pdf

Find John's recent work:
  person:"Smith, John" modified:recent

Find design documents:
  path:design ext:vsdx OR ext:pdf

Exclude archived:
  network -archived -legacy

Multiple tags:
  tag:priority tag:infrastructure
```

---

## 🎨 Use Cases by Role

### **Project Manager**
```
"Show me all projects tagged 'in-progress' with status updates in the last week"
"List projects by completion date"
"Find projects with incomplete deliverables"
```

### **Architect**
```
"Find all architecture decision records (ADR documents)"
"Search for design diagrams related to microservices"
"Show me architecture review documents from Q4 2026"
```

### **Developer**
```
"Find technical specifications for the API project"
"Search for code review documents"
"List projects with technical documentation"
```

### **Compliance Officer**
```
"Find all security assessment documents"
"Search for projects with audit reports"
"List projects that require compliance review"
```

---

## 🚀 Tips for Better Searches

1. **Start Broad, Then Narrow**
   - First: "Search for network projects"
   - Then: "From those, show me ones with Visio diagrams"

2. **Use Exact Phrases for Specific Terms**
   - Use: `"customer portal"`
   - Not: `customer portal` (matches separately)

3. **Combine Operators for Precision**
   - `tag:priority ext:pdf person:Smith`
   - Finds priority PDFs by Smith

4. **Explore Before Searching**
   - "List catalog sources" → See what's available
   - "Show catalog stats" → Understand scope
   - Then search specific catalogs

5. **Use Exclusions to Refine**
   - `architecture -legacy -archived`
   - Removes noise from results

---

## 🔧 Troubleshooting Common Queries

### "No results found"
- Check spelling
- Try broader terms first
- Verify catalog is synced
- Remove some filters

### "Too many results"
- Add more specific operators
- Use exact phrases
- Filter by date/person/tag
- Search specific catalog source

### "Not finding expected project"
- Try partial name: `network` instead of `"network redesign project"`
- Search by person who worked on it
- Check if project is in different catalog source
- Verify project isn't archived

---

## 📞 Getting Help

**In-App Resources:**
- Help & About → SharePoint → MCP integration
- Admin → MCP / AI (for token management)

**Test Your Configuration:**
1. Create token at Admin → MCP / AI
2. Add to AI assistant configuration
3. Try: "List all SharePoint catalog sources"
4. If working, try more complex queries

**Common Issues:**
- Token expired → Create new token
- No sources → Verify catalogs synced
- Permission denied → Check user permissions
- Timeout → Try simpler query first

---

## 🎯 Quick Start Checklist

- [ ] Admin creates MCP token
- [ ] Configure AI assistant (Cursor/Copilot)
- [ ] Test: "List catalog sources"
- [ ] Test: "Search for [common term]"
- [ ] Try advanced operators
- [ ] Explore your catalogs!

---

**Pro Tip:** Save frequently used queries! Your AI assistant can remember context:
```
"Remember this search: tag:priority ext:pdf"
"Run that priority PDF search again"
```

---

**Last Updated:** September 2026  
**Supported AI Assistants:** Claude, Cursor, GitHub Copilot  
**Documentation:** Help & About → SharePoint → MCP integration
